<?php

namespace App\Services;

use App\Exceptions\PurgeBlockedException;
use App\Models\AppSetting;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PurchaseOrderService
{
    public function __construct(private InventoryService $inventory) {}

    /**
     * Create a Purchase Order for a partner.
     *
     * Total dihitung di SERVER dari harga DB sesuai tier pembeli — klien tak bisa
     * mengubah harga/total. Pengecualian: $priceOverrides, khusus entri back-date
     * oleh staf (lihat recordBackdatedSale) — bukan dari form mitra.
     *
     * @param  array<int,array{product_id:int,qty:int}>  $lines
     * @param  array<int, float>  $priceOverrides  [product_id => harga satuan]. Harga
     *                                             LAMA kerap beda dari tier sekarang, jadi harus bisa ditulis apa adanya.
     *                                             Kosong = pakai harga tier (perilaku lama).
     */
    public function createForPartner(User $buyer, array $lines, ?string $shippingAddress, ?string $notes, array $priceOverrides = []): PurchaseOrder
    {
        $clean = [];
        foreach ($lines as $line) {
            $qty = (int) ($line['qty'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $clean[(int) $line['product_id']] = ($clean[(int) $line['product_id']] ?? 0) + $qty;
        }

        if (empty($clean)) {
            throw ValidationException::withMessages([
                'items' => 'Pilih minimal satu produk dengan kuantitas di atas 0.',
            ]);
        }

        $priceField = $buyer->priceField();

        return DB::transaction(function () use ($buyer, $clean, $priceField, $shippingAddress, $notes, $priceOverrides) {
            $products = Product::whereIn('id', array_keys($clean))
                ->where('status', Product::STATUS_ACTIVE)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $subtotal = 0.0;
            $itemsData = [];

            foreach ($clean as $productId => $qty) {
                $product = $products->get($productId);
                if (! $product) {
                    throw ValidationException::withMessages([
                        'items' => "Produk #{$productId} tidak tersedia atau sudah nonaktif.",
                    ]);
                }

                $unitPrice = isset($priceOverrides[$productId])
                    ? (float) $priceOverrides[$productId]
                    : (float) $product->{$priceField};
                $lineTotal = $unitPrice * $qty;
                $subtotal += $lineTotal;

                $itemsData[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'total_price' => $lineTotal,
                ];
            }

            $po = PurchaseOrder::create([
                'po_number' => $this->generatePoNumber(),
                'created_by' => $buyer->id,
                'user_id' => $buyer->id,
                'company_name' => $buyer->company_name,
                'user_role' => $buyer->role,
                'status' => PurchaseOrder::STATUS_PENDING,
                'subtotal' => $subtotal,
                'discount' => 0,
                'shipping_cost' => 0,
                'total_amount' => $subtotal,
                'payment_status' => PurchaseOrder::PAYMENT_UNPAID,
                'shipping_address' => $shippingAddress ?: $buyer->address,
                'notes' => $notes,
            ]);

            $po->items()->createMany($itemsData);

            AuditService::log(
                action: 'create_po',
                targetType: 'purchase_order',
                targetId: $po->id,
                after: ['po_number' => $po->po_number, 'total_amount' => $subtotal, 'status' => $po->status],
            );

            return $po->load('items');
        });
    }

    /**
     * Move a PO to the next status. Fulfilment (inventory transaction) only runs
     * when the PO reaches `completed`, and only once.
     */
    public function updateStatus(PurchaseOrder $po, string $next, ?string $notes = null): PurchaseOrder
    {
        if (! in_array($next, PurchaseOrder::STATUSES, true)) {
            throw new RuntimeException("Status '{$next}' tidak valid.");
        }

        if ($next === $po->status) {
            return $po;
        }

        if (! $po->canTransitionTo($next)) {
            throw new RuntimeException("Transisi status dari '{$po->status}' ke '{$next}' tidak diizinkan.");
        }

        // Payment gate: barang baru boleh diproses/dikirim setelah lunas —
        // KECUALI PO ditandai TEMPO oleh admin (kesepakatan cicil/bayar
        // belakangan). Gerbang untuk PO biasa tetap terkunci rapat.
        $fulfilment = [PurchaseOrder::STATUS_PROCESSING, PurchaseOrder::STATUS_SHIPPED, PurchaseOrder::STATUS_COMPLETED];
        if (in_array($next, $fulfilment, true) && ! $po->isPaid() && ! $po->is_tempo) {
            throw new RuntimeException('PO belum lunas. Verifikasi bukti pembayaran (TF) dulu, atau tandai PO ini sebagai TEMPO bila memang kesepakatannya bayar belakangan.');
        }

        if ($next === PurchaseOrder::STATUS_COMPLETED) {
            return $this->complete($po, $notes);
        }

        $before = $po->status;
        $po->status = $next;
        if ($notes) {
            $po->revision_notes = $notes;
        }
        $po->save();

        AuditService::log(
            action: 'update_po_status',
            targetType: 'purchase_order',
            targetId: $po->id,
            before: ['status' => $before],
            after: ['status' => $next, 'notes' => $notes],
        );

        return $po;
    }

    /**
     * Atomically fulfil a PO:
     *   1. Guard against double completion.
     *   2. Decrement products.hq_stock (fails if insufficient).
     *   3. Add stock to the buyer's inventory line.
     *   4. Write OUT (HQ) + PO_FULFILLMENT (partner) stock movements.
     *   5. Flip status to completed + stamp completed_at.
     *   6. Audit log.
     */
    public function complete(PurchaseOrder $po, ?string $notes = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $notes) {
            $po = PurchaseOrder::with('items')->lockForUpdate()->findOrFail($po->id);

            if ($po->status === PurchaseOrder::STATUS_COMPLETED || $po->completed_at !== null) {
                throw new RuntimeException('PO ini sudah pernah diselesaikan sebelumnya.');
            }

            // Barang untuk order pra-opname sudah keluar SEBELUM stok dihitung —
            // hitungan opname sudah memperhitungkannya. Memotong lagi = hilang dua
            // kali. Jadi PO-nya tetap dicatat (omzet), tapi stok tidak disentuh.
            if ($this->isBeforeStockCutoff($po)) {
                $po->status = PurchaseOrder::STATUS_COMPLETED;
                $po->completed_at = now();
                $po->stock_skipped = true;
                if ($notes) {
                    $po->revision_notes = $notes;
                }
                $po->save();

                AuditService::log(
                    action: 'complete_po_backdated',
                    targetType: 'purchase_order',
                    targetId: $po->id,
                    after: ['stock_skipped' => true, 'order_date' => (string) $po->orderDate()->toDateString()],
                );

                return $po;
            }

            foreach ($po->items as $item) {
                $product = Product::lockForUpdate()->find($item->product_id);
                if (! $product) {
                    throw new RuntimeException("Produk untuk item '{$item->product_name}' tidak ditemukan.");
                }

                if ((int) $product->hq_stock < (int) $item->qty) {
                    throw new RuntimeException(
                        "Stok pusat untuk {$product->name} tidak mencukupi (tersedia {$product->hq_stock}, dibutuhkan {$item->qty}). Penyelesaian PO dibatalkan."
                    );
                }

                // 3 + 4a: OUT from HQ
                $this->inventory->adjustHqStock(
                    product: $product,
                    delta: -1 * (int) $item->qty,
                    movementType: StockMovement::TYPE_OUT,
                    notes: "Pemenuhan PO {$po->po_number}",
                    referenceType: 'purchase_order',
                    referenceId: $po->id,
                );

                // 4 + 4b: PO_FULFILLMENT into partner inventory
                $this->inventory->adjustPartnerStock(
                    userId: $po->user_id,
                    productId: $product->id,
                    delta: (int) $item->qty,
                    movementType: StockMovement::TYPE_PO_FULFILLMENT,
                    notes: "Penerimaan dari PO {$po->po_number}",
                    referenceType: 'purchase_order',
                    referenceId: $po->id,
                );
            }

            $before = $po->status;
            $po->status = PurchaseOrder::STATUS_COMPLETED;
            $po->completed_at = now();
            if ($notes) {
                $po->revision_notes = $notes;
            }
            $po->save();

            AuditService::log(
                action: 'complete_po',
                targetType: 'purchase_order',
                targetId: $po->id,
                before: ['status' => $before],
                after: ['status' => PurchaseOrder::STATUS_COMPLETED, 'completed_at' => (string) $po->completed_at],
            );

            return $po;
        });
    }

    /** Batas tanggal potong stok PO (null = tak ada batas → semua PO memotong stok). */
    public function stockCutoff(): ?Carbon
    {
        return AppSetting::date(AppSetting::PO_DEDUCT_FROM);
    }

    /** PO pra-opname → catat penjualannya, jangan sentuh stok. */
    public function isBeforeStockCutoff(PurchaseOrder $po): bool
    {
        $cut = $this->stockCutoff();

        return $cut && $po->orderDate()->lt($cut);
    }

    /**
     * Catat penjualan distributor yang sudah terjadi (back-date) — langsung
     * selesai. Terpisah dari createForPartner() yang dipakai mitra memesan
     * sendiri, supaya alur mitra yang sudah jalan tak terganggu.
     *
     * Stok dipotong HANYA jika tanggal order >= batas. Untuk order pra-opname
     * stok tidak disentuh sama sekali (HQ maupun mitra) — barangnya sudah keluar
     * sebelum dihitung.
     *
     * @param  array<int, array{product_id:int, qty:int}>  $lines
     */
    public function recordBackdatedSale(User $buyer, array $lines, Carbon $orderDate, ?string $notes, int $creatorId, ?string $buyerName = null): PurchaseOrder
    {
        // Harga manual per baris (opsional) — harga lama kerap beda dari tier saat ini.
        $prices = [];
        foreach ($lines as $l) {
            if (isset($l['price']) && $l['price'] !== null && $l['price'] !== '') {
                $prices[(int) $l['product_id']] = (float) $l['price'];
            }
        }

        $po = $this->createForPartner($buyer, $lines, null, $notes, $prices);

        $attrs = [
            'order_date' => $orderDate->toDateString(),
            'created_by' => $creatorId,
            // Penjualan back-date = transaksi lampau yang UANGNYA SUDAH masuk;
            // tanpa ini ia jatuh ke default 'unpaid' dan tampil sebagai piutang
            // palsu. paid_at diset ke tanggal transaksinya, bukan sekarang.
            'payment_status' => PurchaseOrder::PAYMENT_PAID,
            'paid_at' => $orderDate,
        ];
        // Pembeli sekali-beli (mis. "Vani") tak perlu dibuatkan akun — namanya
        // disimpan di PO. company_name memang field snapshot, bukan relasi.
        if ($buyerName) {
            $attrs['company_name'] = $buyerName;
        }
        $po->update($attrs);

        return $this->complete($po->fresh(), $notes);
    }

    public function cancel(PurchaseOrder $po, ?string $reason = null): PurchaseOrder
    {
        if ($po->status === PurchaseOrder::STATUS_COMPLETED) {
            throw new RuntimeException('PO yang sudah selesai tidak dapat dibatalkan.');
        }

        $before = $po->status;
        $po->status = PurchaseOrder::STATUS_CANCELLED;
        $po->revision_notes = $reason ?: $po->revision_notes;
        $po->save();

        AuditService::log(
            action: 'cancel_po',
            targetType: 'purchase_order',
            targetId: $po->id,
            before: ['status' => $before],
            after: ['status' => PurchaseOrder::STATUS_CANCELLED, 'reason' => $reason],
        );

        return $po;
    }

    /** Admin sets/updates the shipping cost; total is recomputed. */
    public function setShipping(PurchaseOrder $po, float $shippingCost, ?float $discount = null): PurchaseOrder
    {
        $before = ['shipping_cost' => (float) $po->shipping_cost, 'total_amount' => (float) $po->total_amount];

        $po->shipping_cost = max(0, $shippingCost);
        if ($discount !== null) {
            $po->discount = max(0, $discount);
        }
        $po->recalcTotal();
        $po->save();

        AuditService::log(
            action: 'set_po_shipping',
            targetType: 'purchase_order',
            targetId: $po->id,
            before: $before,
            after: ['shipping_cost' => (float) $po->shipping_cost, 'total_amount' => (float) $po->total_amount],
        );

        return $po;
    }

    /** After a transfer proof file is attached, move payment to awaiting verification. */
    public function recordPaymentProof(PurchaseOrder $po, ?string $note = null): PurchaseOrder
    {
        $po->payment_status = PurchaseOrder::PAYMENT_AWAITING;
        $po->payment_note = $note;
        $po->paid_at = null;
        $po->payment_verified_by = null;
        $po->save();

        AuditService::log(
            action: 'upload_payment_proof',
            targetType: 'purchase_order',
            targetId: $po->id,
            after: ['payment_status' => $po->payment_status],
        );

        return $po;
    }

    /** Admin verifies (approve = paid) or rejects a payment. */
    public function verifyPayment(PurchaseOrder $po, bool $approve, ?int $verifierId, ?string $note = null): PurchaseOrder
    {
        $before = $po->payment_status;

        if ($approve) {
            $po->payment_status = PurchaseOrder::PAYMENT_PAID;
            $po->paid_at = now();
            $po->payment_verified_by = $verifierId;
        } else {
            $po->payment_status = PurchaseOrder::PAYMENT_REJECTED;
            $po->paid_at = null;
            $po->payment_verified_by = $verifierId;
        }
        if ($note) {
            $po->payment_note = $note;
        }
        $po->save();

        AuditService::log(
            action: $approve ? 'verify_payment_paid' : 'verify_payment_rejected',
            targetType: 'purchase_order',
            targetId: $po->id,
            before: ['payment_status' => $before],
            after: ['payment_status' => $po->payment_status],
        );

        return $po;
    }

    /**
     * Tandai / cabut status TEMPO — pintu terkontrol melewati gerbang
     * pembayaran. Hanya lewat sini (tercatat siapa & kenapa), bukan dengan
     * melonggarkan gerbangnya untuk semua PO.
     */
    public function setTempo(PurchaseOrder $po, bool $on, ?string $notes, ?string $dueDate): PurchaseOrder
    {
        $po->update([
            'is_tempo' => $on,
            'tempo_notes' => $on ? $notes : null,
            'tempo_due_date' => $on ? $dueDate : null,
        ]);

        AuditService::log(
            action: $on ? 'set_po_tempo' : 'unset_po_tempo',
            targetType: 'purchase_order',
            targetId: $po->id,
            after: ['po' => $po->po_number, 'catatan' => $notes, 'jatuh_tempo' => $dueDate],
        );

        return $po;
    }

    /**
     * Catat satu cicilan. Melebihi sisa tagihan DITOLAK — kelebihan bayar
     * hampir pasti salah ketik, dan diam-diam menerimanya mengacaukan piutang.
     * Lunas otomatis saat sisa mencapai nol (payment_status -> paid), jadi
     * "siapa belum lunas" tetap satu sumber kebenaran: payment_status.
     */
    public function recordPayment(PurchaseOrder $po, float $amount, string $paidAt, ?string $notes, int $userId): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $amount, $paidAt, $notes, $userId) {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($po->id);

            if ($amount <= 0) {
                throw new RuntimeException('Jumlah cicilan harus lebih dari nol.');
            }

            $sisa = $po->remaining();
            if ($amount > $sisa + 0.01) {
                throw new RuntimeException(
                    'Jumlah melebihi sisa tagihan (Rp '.number_format($sisa, 0, ',', '.').'). Periksa lagi angkanya.'
                );
            }

            $po->payments()->create([
                'amount' => $amount,
                'paid_at' => $paidAt,
                'notes' => $notes,
                'created_by' => $userId,
            ]);

            $lunas = $po->remaining() <= 0.01;
            if ($lunas) {
                $po->update(['payment_status' => PurchaseOrder::PAYMENT_PAID]);
            }

            AuditService::log(
                action: 'record_po_payment',
                targetType: 'purchase_order',
                targetId: $po->id,
                after: [
                    'po' => $po->po_number, 'jumlah' => $amount, 'tanggal' => $paidAt,
                    'sisa' => $po->remaining(), 'lunas' => $lunas,
                ],
            );

            return $po->fresh();
        });
    }

    private function generatePoNumber(): string
    {
        $date = now()->format('Ymd');
        do {
            $candidate = sprintf('SKN-PO-%s-%04d', $date, random_int(1, 9999));
        } while (PurchaseOrder::where('po_number', $candidate)->exists());

        return $candidate;
    }

    /**
     * Hapus PO SEOLAH TAK PERNAH ADA: batalkan efek stoknya, buang jejak
     * gerakannya, lalu hapus permanen. Untuk membersihkan data uji coba.
     *
     * Ini berbeda dari delete() (soft delete, stok DIBIARKAN) dan dari
     * forceDestroy() lama yang menghapus PO tapi meninggalkan stock_movements
     * menunjuk ke id yang sudah lenyap — stok tetap terpotong tanpa jejak asal.
     *
     * Gerakannya DIHAPUS, bukan dikompensasi dengan gerakan lawan: data uji
     * coba tak boleh meninggalkan riwayat. Karena itu saldo disetel langsung
     * tanpa menulis movement baru — menulisnya justru mengotori yang mau dibuang.
     *
     * MENOLAK bila ada saldo yang jadi negatif. Contoh nyata: PO menaruh 300 di
     * mitra, lalu 5 keluar lewat gerakan LAIN; membatalkan 300 dari saldo 295
     * menghasilkan -5. Memaksakannya = mengarang 5 unit dari udara. Yang begini
     * harus dilihat manusia, bukan dibulatkan diam-diam.
     *
     * @param  bool  $dryRun  true = hitung & laporkan saja, tidak mengubah apa pun
     * @return array{actions: array<int, string>, blockers: array<int, string>, movements: int}
     */
    /**
     * Stok opname untuk produk ini yang terjadi SETELAH $sejak, kalau ada.
     *
     * Opname dicari pada gerakan HQ (user_id null) — di situlah hitungan fisik
     * gudang pusat dicatat.
     */
    private function opnameSesudah(int $productId, ?Carbon $sejak): ?StockMovement
    {
        if (! $sejak) {
            return null;
        }

        return StockMovement::query()
            ->whereNull('user_id')
            ->where('product_id', $productId)
            ->where('reference_type', 'opname')
            ->where('created_at', '>', $sejak)
            ->orderByDesc('created_at')
            ->first();
    }

    public function purge(PurchaseOrder $po, bool $dryRun = true): array
    {
        return DB::transaction(function () use ($po, $dryRun) {
            $moves = StockMovement::query()
                ->where('reference_type', 'purchase_order')
                ->where('reference_id', $po->id)
                ->get();

            // Efek bersih PO ini per (pemilik, produk). Dihitung dari
            // after-before, bukan kolom quantity yang disimpan tanpa tanda.
            $efek = [];
            foreach ($moves as $m) {
                $key = ($m->user_id ?? 'hq').':'.$m->product_id;
                $efek[$key] ??= [
                    'user_id' => $m->user_id,
                    'product_id' => $m->product_id,
                    'delta' => 0,
                    'paling_awal' => $m->created_at,
                ];
                $efek[$key]['delta'] += (int) $m->after_qty - (int) $m->before_qty;

                if ($m->created_at && $m->created_at->lt($efek[$key]['paling_awal'])) {
                    $efek[$key]['paling_awal'] = $m->created_at;
                }
            }

            $actions = [];
            $blockers = [];

            foreach ($efek as $e) {
                $balik = -1 * $e['delta'];
                $product = Product::lockForUpdate()->find($e['product_id']);

                if (! $product) {
                    $blockers[] = "Produk id {$e['product_id']} sudah tidak ada — tak bisa dikoreksi.";

                    continue;
                }

                // Opname menyetel saldo mengikuti hitungan FISIK: apa pun yang
                // terjadi sebelum tanggalnya sudah diperhitungkan olehnya.
                // Membatalkan gerakan pra-opname lalu mengembalikan stoknya =
                // menambah barang yang hitungan fisik sudah bilang tidak ada.
                if ($opname = $this->opnameSesudah($e['product_id'], $e['paling_awal'])) {
                    $blockers[] = sprintf(
                        '%s: gerakan PO ini (%s) jatuh SEBELUM stok opname %s yang menyetel saldo ke %s'
                        .' mengikuti hitungan fisik. Opname sudah memperhitungkannya — membalikkannya sekarang'
                        .' akan menggandakan %d unit.',
                        $product->name,
                        $e['paling_awal']->format('d M Y'),
                        $opname->created_at->format('d M Y'),
                        number_format((int) $opname->after_qty, 0, ',', '.'),
                        abs($balik),
                    );

                    continue;
                }

                if ($e['user_id'] === null) {
                    $before = (int) $product->hq_stock;
                    $after = $before + $balik;
                    $label = "Stok HQ {$product->name}: {$before} → {$after}";

                    if ($after < 0) {
                        $blockers[] = $label.' — NEGATIF, dibatalkan.';

                        continue;
                    }

                    $actions[] = $label;
                    if (! $dryRun) {
                        $product->hq_stock = $after;
                        $product->save();
                    }

                    continue;
                }

                $line = Inventory::lockForUpdate()
                    ->where('user_id', $e['user_id'])
                    ->where('product_id', $e['product_id'])
                    ->first();
                $before = (int) ($line->quantity ?? 0);
                $after = $before + $balik;
                $nama = User::withTrashed()->find($e['user_id'])?->company_name ?? "user id {$e['user_id']}";
                $label = "Stok {$nama} {$product->name}: {$before} → {$after}";

                if ($after < 0) {
                    $blockers[] = $label.' — NEGATIF: ada gerakan LAIN di luar PO ini yang sudah'
                        .' memindahkan '.abs($after).' unit. Selesaikan itu dulu.';

                    continue;
                }

                $actions[] = $label;
                if (! $dryRun && $line) {
                    $line->quantity = $after;
                    $line->save();
                }
            }

            if ($blockers) {
                // Satu saldo negatif membatalkan SELURUH purge: separuh koreksi
                // jauh lebih berbahaya daripada tidak dikoreksi sama sekali.
                throw new PurgeBlockedException($blockers, $actions, $moves->count());
            }

            $hasil = ['actions' => $actions, 'blockers' => [], 'movements' => $moves->count()];

            if ($dryRun) {
                return $hasil;
            }

            StockMovement::where('reference_type', 'purchase_order')->where('reference_id', $po->id)->delete();
            $po->files()->get()->each->delete();
            $po->items()->delete();

            // Pelaku diambil AuditService dari Auth::user(); lewat CLI itu null,
            // jadi perintahnya wajib login sebagai super admin dulu — penghapusan
            // permanen tanpa nama pelaku tidak boleh terjadi.
            AuditService::log(
                action: 'purge_po',
                targetType: 'purchase_order',
                targetId: $po->id,
                before: ['po_number' => $po->po_number, 'total_amount' => $po->total_amount, 'status' => $po->status],
                after: ['dibatalkan' => $actions, 'gerakan_dihapus' => $moves->count()],
            );

            $po->forceDelete();

            return $hasil;
        });
    }
}
