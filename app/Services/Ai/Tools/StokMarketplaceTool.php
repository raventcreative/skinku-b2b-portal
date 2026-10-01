<?php

namespace App\Services\Ai\Tools;

use App\Models\MarketplaceMaster;
use App\Models\User;
use App\Services\MarketplaceMasterService;

/**
 * Alat BACA: stok tampil Produk Master E-commerce (TikTok/Shopee). Ini buku stok
 * marketplace, TERPISAH dari stok gudang HQ. Izin = izin menu Produk Master.
 */
class StokMarketplaceTool extends BaseTool
{
    public function __construct(private MarketplaceMasterService $svc) {}

    public function name(): string
    {
        return 'stok_marketplace';
    }

    public function permission(): ?string
    {
        return 'manage_marketplace_stock';
    }

    public function description(): string
    {
        return 'Ambil stok & harga Produk Master E-commerce per channel (TikTok, Shopee), termasuk varian dan '
            .'stok bundle yang dihitung dari isinya. Ini stok etalase marketplace, BUKAN stok gudang HQ.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'cari' => ['type' => 'string', 'description' => 'Saring nama/SKU master (opsional).'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        $term = trim((string) ($args['cari'] ?? ''));

        $masters = MarketplaceMaster::query()
            ->with('variants')
            ->whereNull('parent_id')
            ->when($term !== '', fn ($q) => $q->where(fn ($s) => $s->where('name', 'like', "%{$term}%")
                ->orWhere('master_sku', 'like', "%{$term}%")
                ->orWhereHas('variants', fn ($v) => $v->where('master_sku', 'like', "%{$term}%"))))
            ->orderBy('name')
            ->get();

        $baris = fn (MarketplaceMaster $m) => [
            'sku' => $m->master_sku,
            'nama' => $m->variant_name ?: $m->name,
            'bundle' => (bool) $m->is_bundle,
            'harga' => $m->base_price !== null ? (float) $m->base_price : null,
            'stok_tiktok' => $this->svc->effectiveStock($m, 'tiktok'),
            'stok_shopee' => $this->svc->effectiveStock($m, 'shopee'),
        ];

        return [
            'catatan' => 'Stok null = belum diisi / isi bundle belum punya stok.',
            'jumlah' => $masters->count(),
            // ponytail: 30 master per jawaban; saring pakai 'cari' bila produknya banyak.
            'produk' => $masters->take(30)->map(fn (MarketplaceMaster $m) => $baris($m)
                + ($m->variants->isNotEmpty() ? ['varian' => $m->variants->map($baris)->values()->all()] : []))
                ->values()->all(),
        ];
    }
}
