<?php

namespace App\Services\Ai\Tools;

use App\Models\Product;
use App\Models\User;

/**
 * Alat BACA: katalog Produk Master (menu Manajemen Produk → Produk Master, route products.index). Izin sama dgn
 * halamannya (manage_products); kolom = tabel halaman: harga per tier, HPP, berat, stok pusat, status. Bukan
 * "Produk Master E-commerce" (stok etalase TikTok/Shopee → alat stok_marketplace).
 */
class ProdukMasterTool extends BaseTool
{
    public function name(): string
    {
        return 'produk_master';
    }

    public function permission(): ?string
    {
        return 'manage_products';
    }

    public function description(): string
    {
        return 'Katalog Produk Master SKINKU (menu Manajemen Produk → Produk Master): SKU, kategori, harga per tier '
            .'(grand, distributor, reseller, retail), berat, stok pusat/HQ, status, + HPP rata-rata khusus izin Lihat HPP. '
            .'Bisa cari nama/SKU/kategori. Bukan stok etalase TikTok/Shopee (itu stok_marketplace).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'cari' => ['type' => 'string', 'description' => 'Opsional: kata di nama, SKU, atau kategori.'],
                'status' => ['type' => 'string', 'enum' => [Product::STATUS_ACTIVE, Product::STATUS_INACTIVE], 'description' => 'Opsional. Default aktif & nonaktif.'],
                'limit' => ['type' => 'integer', 'description' => 'Jumlah produk ditampilkan, 1-50. Default 20.'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        $cari = trim((string) ($args['cari'] ?? ''));
        $status = in_array($args['status'] ?? null, [Product::STATUS_ACTIVE, Product::STATUS_INACTIVE], true) ? $args['status'] : null;
        // Sama dgn ProductController@index: cari nama/SKU/kategori, saring status, urut nama; produk terhapus (soft delete) tak ikut.
        $produk = Product::query()
            ->when($cari !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$cari}%")
                ->orWhere('sku', 'like', "%{$cari}%")->orWhere('category', 'like', "%{$cari}%")))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderBy('name')->get();
        $limit = max(1, min(50, (int) ($args['limit'] ?? 20)));
        $rp = fn ($v) => $v === null ? null : (float) $v;
        $hpp = $this->bolehLihatHpp($user);

        return array_filter([
            'ringkasan' => [
                'jumlah_produk' => $produk->count(),
                'per_status' => $produk->countBy('status')->all(),
                'total_stok_pusat' => (int) $produk->sum('hq_stock'),
            ],
            'produk' => $produk->take($limit)->map(fn (Product $p) => [
                'nama' => $p->name,
                'sku' => $p->sku,
                'kategori' => $p->category,
                'harga' => [
                    'grand' => $rp($p->price_grand),
                    'distributor' => $rp($p->price_distributor),
                    'reseller' => $rp($p->price_reseller),
                    'retail' => $rp($p->price_retail),
                ],
            ] + ($hpp ? ['hpp' => $rp($p->cogs)] : []) + [
                'berat_gram' => $p->weight_grams ?: null,
                'stok_pusat' => (int) $p->hq_stock,
                'status' => $p->status,
            ])->values()->all(),
            'catatan_akses' => $hpp ? null : self::CATATAN_HPP,
            'catatan' => $produk->count() > $limit
                ? "Menampilkan {$limit} dari {$produk->count()} produk (urut nama) — persempit dgn kata cari."
                : null,
        ], fn ($v) => $v !== null);
    }
}
