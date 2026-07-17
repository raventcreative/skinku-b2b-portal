<?php

namespace App\Services;

use App\Models\AppSetting;
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
     * The total is ALWAYS computed on the server from current DB prices based
     * on the buyer's role — the client cannot influence pricing or totals.
     *
     * @param  array<int,array{product_id:int,qty:int}>  $lines
     */
    public function createForPartner(User $buyer, array $lines, ?string $shippingAddress, ?string $notes): PurchaseOrder
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

        return DB::transaction(function () use ($buyer, $clean, $priceField, $shippingAddress, $notes) {
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

                $unitPrice = (float) $product->{$priceField};
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

        // Payment gate: barang baru boleh diproses/dikirim setelah pembayaran lunas (terverifikasi).
        $fulfilment = [PurchaseOrder::STATUS_PROCESSING, PurchaseOrder::STATUS_SHIPPED, PurchaseOrder::STATUS_COMPLETED];
        if (in_array($next, $fulfilment, true) && ! $po->isPaid()) {
            throw new RuntimeException('PO belum lunas. Verifikasi bukti pembayaran (TF) dulu sebelum memproses/mengirim barang.');
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
    public function recordBackdatedSale(User $buyer, array $lines, Carbon $orderDate, ?string $notes, int $creatorId): PurchaseOrder
    {
        $po = $this->createForPartner($buyer, $lines, null, $notes);
        $po->update(['order_date' => $orderDate->toDateString(), 'created_by' => $creatorId]);

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

    private function generatePoNumber(): string
    {
        $date = now()->format('Ymd');
        do {
            $candidate = sprintf('SKN-PO-%s-%04d', $date, random_int(1, 9999));
        } while (PurchaseOrder::where('po_number', $candidate)->exists());

        return $candidate;
    }
}
