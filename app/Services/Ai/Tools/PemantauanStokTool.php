<?php

namespace App\Services\Ai\Tools;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;

/**
 * Alat BACA: stok gudang mitra (menu Pemantauan Stok [staf] / Stok Saya [mitra], route inventory.index — middleware
 * business, tanpa izin khusus). Cakupan = InventoryController@index: mitra hanya stok miliknya (qty > 0); staf semua
 * baris semua mitra (termasuk qty 0) + tabel stok pusat bila punya manage_hq_stock. Dari data mitra hanya nama
 * tampil yang dikirim (tanpa telepon/alamat/rekening).
 */
class PemantauanStokTool extends BaseTool
{
    public function name(): string
    {
        return 'pemantauan_stok';
    }

    public function availableFor(User $user): bool
    {
        return $user->isStaff() || $user->isPartner();
    }

    public function description(): string
    {
        return 'Stok barang di gudang mitra (menu Pemantauan Stok / Stok Saya). Mitra: stok miliknya sendiri. Staf: stok '
            .'semua mitra + total per produk (+ stok pusat HQ bila punya izin Stok HQ). Menandai stok menipis (≤ stok '
            .'minimum yang diisi). Bisa cari produk, nama mitra (staf), atau hanya yang menipis.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'produk' => ['type' => 'string', 'description' => 'Opsional: kata di nama/SKU produk.'],
                'mitra' => ['type' => 'string', 'description' => 'Opsional (staf saja): kata di nama mitra/perusahaan.'],
                'hanya_menipis' => ['type' => 'boolean', 'description' => 'Opsional: true = hanya stok ≤ minimum.'],
                'limit' => ['type' => 'integer', 'description' => 'Jumlah baris stok, 1-50. Default 30.'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        $staf = $user->isStaff();
        $produk = trim((string) ($args['produk'] ?? ''));
        $mitra = $staf ? trim((string) ($args['mitra'] ?? '')) : '';
        $cariProduk = fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$produk}%")->orWhere('sku', 'like', "%{$produk}%"));
        // Aturan "menipis" halaman: minimum diisi (> 0) dan qty ≤ minimum (Inventory::isLow saja tanpa syarat > 0).
        $menipis = fn (Inventory $i) => $i->minimum_stock > 0 && $i->isLow();

        // Sama dgn InventoryController@index: mitra = miliknya & qty > 0; staf = semua baris semua mitra.
        $rows = Inventory::query()->with($staf ? ['product', 'user'] : ['product'])
            ->when(! $staf, fn ($q) => $q->where('user_id', $user->id)->where('quantity', '>', 0))
            ->when($produk !== '', fn ($q) => $q->whereHas('product', $cariProduk))
            ->when($mitra !== '', fn ($q) => $q->whereHas('user', fn ($u) => $u->where(fn ($w) => $w
                ->where('company_name', 'like', "%{$mitra}%")->orWhere('fullname', 'like', "%{$mitra}%"))))
            ->when(($args['hanya_menipis'] ?? null) === true, fn ($q) => $q->where('minimum_stock', '>', 0)->whereColumn('quantity', '<=', 'minimum_stock'))
            ->orderByDesc('updated_at')->get();
        $limit = max(1, min(50, (int) ($args['limit'] ?? 30)));

        $out = [
            'cakupan' => $staf ? 'stok semua mitra' : 'stok milikmu sendiri',
            'ringkasan' => ['jumlah_baris' => $rows->count(), 'total_qty' => (int) $rows->sum('quantity'), 'menipis' => $rows->filter($menipis)->count()],
        ];
        if ($staf) {
            $out['per_produk'] = $rows->groupBy('product_id')
                ->map(fn ($g) => ['produk' => $g->first()->product?->name, 'total_qty' => (int) $g->sum('quantity'), 'jumlah_mitra' => $g->count()])
                ->sortByDesc('total_qty')->take(20)->values()->all();
        }
        $out['stok'] = $rows->take($limit)->map(fn (Inventory $i) => array_filter([
            'mitra' => $staf ? ($i->user?->company_name ?: $i->user?->fullname) : null,
            'produk' => $i->product?->name,
            'sku' => $i->product?->sku,
            'qty' => (int) $i->quantity,
            'minimum' => (int) $i->minimum_stock,
            'menipis' => $menipis($i),
        ], fn ($v) => $v !== null))->values()->all();

        if ($staf && $user->canDo('manage_hq_stock')) {
            // Tabel "Stok Pusat" halaman: produk selain terhapus (aktif & nonaktif), urut nama.
            $out['stok_pusat'] = Product::where('status', '!=', Product::STATUS_DELETED)
                ->when($produk !== '', $cariProduk)
                ->orderBy('name')->limit(50)->get(['name', 'sku', 'hq_stock'])
                ->map(fn (Product $p) => ['produk' => $p->name, 'sku' => $p->sku, 'stok' => (int) $p->hq_stock])->all();
        }
        if ($rows->count() > $limit) {
            $out['catatan'] = "Menampilkan {$limit} dari {$rows->count()} baris stok (terbaru diperbarui) — persempit dgn produk/mitra.";
        }

        return $out;
    }
}
