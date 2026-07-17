<?php

namespace App\Console\Commands;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use Illuminate\Console\Command;

/**
 * Bongkar angka "Stok Mitra" pada grafik Stok HQ vs Mitra: siapa memegang berapa.
 *
 * Grafik itu cuma menjumlahkan SELURUH baris inventory per produk, tanpa peduli
 * pemiliknya masih aktif, sudah nonaktif, atau sudah dihapus. Menghapus anggota
 * dan menghapus PO sama-sama TIDAK menyentuh inventory — keduanya soft delete.
 * Jadi angka mitra bisa memuat stok milik akun yang sudah tak ada di daftar.
 *
 * Perintah ini membacanya apa adanya lewat withTrashed() supaya pemilik yang
 * sudah dihapus tetap kelihatan, bukan hilang jadi baris tanpa nama.
 */
class StockHoldersCommand extends Command
{
    protected $signature = 'stock:holders
        {cari? : Cari produk berdasarkan nama atau SKU (kosong = semua produk yang ada stok mitranya)}
        {--zero : Ikut tampilkan baris ber-qty 0}
        {--trace : Bongkar riwayat gerakan stok tiap pemegang, lengkap dengan PO asalnya}';

    protected $description = 'Tampilkan siapa saja yang memegang stok mitra per produk (termasuk akun terhapus)';

    public function handle(): int
    {
        $cari = $this->argument('cari');

        $products = Product::query()
            ->when($cari, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$cari}%")->orWhere('sku', 'like', "%{$cari}%")))
            ->orderBy('name')
            ->get();

        if ($products->isEmpty()) {
            $this->error("Tidak ada produk yang cocok dengan \"{$cari}\".");

            return self::FAILURE;
        }

        $adaIsi = false;

        foreach ($products as $product) {
            $rows = Inventory::query()
                ->with(['user' => fn ($q) => $q->withTrashed()])
                ->where('product_id', $product->id)
                ->when(! $this->option('zero'), fn ($q) => $q->where('quantity', '!=', 0))
                ->orderByDesc('quantity')
                ->get();

            // Dengan --trace produknya tetap ditampilkan walau stok mitranya nol:
            // riwayat HQ-nya (termasuk opname) justru yang paling perlu dilihat
            // setelah stok mitra dibereskan.
            if ($rows->isEmpty() && ! $this->option('trace')) {
                continue;
            }

            $adaIsi = true;
            $total = (int) $rows->sum('quantity');

            $this->newLine();
            $this->line("<options=bold>{$product->name}</> (SKU {$product->sku})");
            $this->line('Stok HQ: <fg=cyan>'.number_format((int) $product->hq_stock, 0, ',', '.').'</>  |  Stok Mitra (angka di grafik): <fg=yellow>'.number_format($total, 0, ',', '.').'</>');

            if ($rows->isEmpty()) {
                $this->line('  <fg=gray>(tidak ada stok mitra)</>');
            }

            $this->table(
                ['Pemilik', 'Peran', 'Status akun', 'Qty', 'Terakhir diubah'],
                $rows->map(function (Inventory $inv) {
                    $u = $inv->user;
                    $terhapus = $u && $u->trashed();

                    return [
                        $u?->company_name ?: ($u?->fullname ?? '(user hilang — id '.$inv->user_id.')'),
                        $u?->role ?? '?',
                        $terhapus ? '⚠ DIHAPUS' : ($u?->status ?? '?'),
                        number_format((int) $inv->quantity, 0, ',', '.'),
                        $inv->updated_at?->format('d M Y H:i') ?? '-',
                    ];
                })->all(),
            );

            $hantu = $rows->filter(fn (Inventory $inv) => ! $inv->user || $inv->user->trashed());

            if ($hantu->isNotEmpty()) {
                $this->warn(sprintf(
                    '⚠ %s unit dipegang %d akun yang sudah dihapus/hilang, tapi TETAP dihitung di grafik.',
                    number_format((int) $hantu->sum('quantity'), 0, ',', '.'),
                    $hantu->count(),
                ));
            }

            if ($this->option('trace')) {
                $this->traceHq($product);
                foreach ($rows as $inv) {
                    $this->traceMovements($product, $inv);
                }
            }
        }

        if (! $adaIsi) {
            $this->info('Tidak ada stok mitra untuk produk tersebut.');
        }

        return self::SUCCESS;
    }

    /**
     * Riwayat stok PUSAT (user_id null), dengan opname ditandai.
     *
     * Ini yang menentukan apakah koreksi stok boleh dilakukan. Opname menyetel
     * saldo mengikuti hitungan FISIK: gerakan sebelum opname sudah "diselesaikan"
     * olehnya. Membatalkan PO yang jatuh SEBELUM opname lalu mengembalikan
     * stoknya = menambah barang yang hitungan fisik sudah bilang tidak ada.
     */
    private function traceHq(Product $product): void
    {
        $moves = StockMovement::query()
            ->whereNull('user_id')
            ->where('product_id', $product->id)
            ->orderBy('created_at')
            ->get();

        $this->newLine();
        $this->line("  <options=bold>Riwayat: STOK PUSAT (HQ)</> — {$product->name}");

        if ($moves->isEmpty()) {
            $this->warn('  ⚠ Saldo HQ '.number_format((int) $product->hq_stock, 0, ',', '.').' unit TANPA satu pun gerakan — tak ada jejak sama sekali.');

            return;
        }

        $opname = $moves->firstWhere('reference_type', 'opname');

        $this->table(
            ['Tanggal', 'Jenis', 'Qty', 'Saldo', 'Asal', 'Catatan'],
            $moves->map(function (StockMovement $m) {
                $delta = (int) $m->after_qty - (int) $m->before_qty;
                $asal = $m->reference_type === 'opname'
                    ? '★ OPNAME'
                    : ($m->reference_type ? $m->reference_type.' #'.$m->reference_id : '-');

                return [
                    $m->created_at?->format('d M Y H:i') ?? '-',
                    $m->movement_type,
                    ($delta > 0 ? '+' : ($delta < 0 ? '−' : '')).number_format(abs($delta), 0, ',', '.'),
                    number_format((int) $m->before_qty, 0, ',', '.').' → '.number_format((int) $m->after_qty, 0, ',', '.'),
                    $asal,
                    $m->notes ?: '-',
                ];
            })->all(),
        );

        $terakhir = (int) $moves->last()->after_qty;
        if ($terakhir !== (int) $product->hq_stock) {
            $this->warn(sprintf(
                '  ⚠ Saldo HQ (%s) TIDAK cocok dengan gerakan terakhir (%s) — ada perubahan tanpa jejak.',
                number_format((int) $product->hq_stock, 0, ',', '.'),
                number_format($terakhir, 0, ',', '.'),
            ));
        }

        if ($opname) {
            $this->line('  <fg=cyan>★ Opname '.$opname->created_at?->format('d M Y').': saldo disetel ke '
                .number_format((int) $opname->after_qty, 0, ',', '.').' mengikuti hitungan fisik.</>');
            $this->warn('  ⚠ Gerakan SEBELUM tanggal itu sudah diperhitungkan opname. Membatalkannya');
            $this->warn('    dan mengembalikan stoknya = menambah barang yang hitungan fisik bilang tidak ada.');
        } else {
            $this->line('  <fg=gray>Belum pernah ada opname untuk produk ini.</>');
        }
    }

    /**
     * Riwayat gerakan stok satu pemegang untuk satu produk, plus PO asalnya.
     *
     * PO dicari dengan withTrashed(): menghapus PO tidak mengembalikan stok dan
     * tidak menyentuh stock_movements sama sekali, jadi gerakan yang berasal dari
     * PO terhapus tetap membentuk saldo. Tanpa withTrashed() barisnya jadi
     * "PO ?" dan justru sumber kebingungannya yang hilang dari layar.
     */
    private function traceMovements(Product $product, Inventory $inv): void
    {
        $moves = StockMovement::query()
            ->where('product_id', $product->id)
            ->where('user_id', $inv->user_id)
            ->orderBy('created_at')
            ->get();

        $nama = $inv->user?->company_name ?: ($inv->user?->fullname ?? 'user id '.$inv->user_id);

        $this->newLine();
        $this->line("  <options=bold>Riwayat: {$nama}</> — {$product->name}");

        if ($moves->isEmpty()) {
            $this->warn('  ⚠ Saldo '.number_format((int) $inv->quantity, 0, ',', '.').' unit TANPA satu pun gerakan stok — baris ini masuk tanpa jejak (impor/seed/manual DB).');

            return;
        }

        // PO diambil sekaligus supaya tidak query per baris.
        $poIds = $moves->where('reference_type', 'purchase_order')->pluck('reference_id')->filter()->unique();
        $pos = $poIds->isEmpty()
            ? collect()
            : PurchaseOrder::withTrashed()->whereIn('id', $poIds)->get()->keyBy('id');

        $adaPoTerhapus = false;

        $baris = $moves->map(function (StockMovement $m) use ($pos, &$adaPoTerhapus) {
            $asal = '-';

            if ($m->reference_type === 'purchase_order') {
                $po = $pos->get($m->reference_id);
                if (! $po) {
                    $asal = 'PO id '.$m->reference_id.' (HILANG PERMANEN)';
                    $adaPoTerhapus = true;
                } else {
                    $asal = $po->po_number;
                    if ($po->trashed()) {
                        $asal .= ' ⚠ DIHAPUS';
                        $adaPoTerhapus = true;
                    }
                }
            } elseif ($m->reference_type) {
                $asal = $m->reference_type.' #'.$m->reference_id;
            }

            // Arah diturunkan dari after-before, BUKAN dari kolom quantity:
            // quantity disimpan sebagai abs($delta), jadi memakainya apa adanya
            // menampilkan "+5" untuk barang yang justru keluar.
            $delta = (int) $m->after_qty - (int) $m->before_qty;

            return [
                $m->created_at?->format('d M Y H:i') ?? '-',
                $m->movement_type,
                ($delta > 0 ? '+' : ($delta < 0 ? '−' : '')).number_format(abs($delta), 0, ',', '.'),
                number_format((int) $m->before_qty, 0, ',', '.').' → '.number_format((int) $m->after_qty, 0, ',', '.'),
                $asal,
                $m->notes ?: '-',
            ];
        })->all();

        $this->table(['Tanggal', 'Jenis', 'Qty', 'Saldo', 'Asal', 'Catatan'], $baris);

        // Saldo inventory vs saldo terakhir menurut gerakan: kalau beda, ada yang
        // mengubah inventory tanpa mencatat gerakannya.
        $terakhir = (int) $moves->last()->after_qty;
        if ($terakhir !== (int) $inv->quantity) {
            $this->warn(sprintf(
                '  ⚠ Saldo inventory (%s) TIDAK cocok dengan gerakan terakhir (%s) — ada perubahan tanpa jejak.',
                number_format((int) $inv->quantity, 0, ',', '.'),
                number_format($terakhir, 0, ',', '.'),
            ));
        }

        if ($adaPoTerhapus) {
            $this->warn('  ⚠ Ada PO terhapus di riwayat ini. Menghapus PO TIDAK menarik balik stoknya — saldo di atas tetap utuh.');
        }
    }
}
