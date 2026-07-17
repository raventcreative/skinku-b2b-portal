<?php

namespace App\Console\Commands;

use App\Models\Inventory;
use App\Models\Product;
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
        {--zero : Ikut tampilkan baris ber-qty 0}';

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

            if ($rows->isEmpty()) {
                continue;
            }

            $adaIsi = true;
            $total = (int) $rows->sum('quantity');

            $this->newLine();
            $this->line("<options=bold>{$product->name}</> (SKU {$product->sku})");
            $this->line('Stok HQ: <fg=cyan>'.number_format((int) $product->hq_stock, 0, ',', '.').'</>  |  Stok Mitra (angka di grafik): <fg=yellow>'.number_format($total, 0, ',', '.').'</>');

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
        }

        if (! $adaIsi) {
            $this->info('Tidak ada stok mitra untuk produk tersebut.');
        }

        return self::SUCCESS;
    }
}
