<?php

namespace App\Services;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceStock;
use App\Models\Product;
use App\Models\ShopeeSkuMap;
use App\Models\TiktokSkuMap;
use Illuminate\Support\Carbon;

/**
 * Kontrol Stok Marketplace — hitung "siap jual" (available-to-sell) per listing
 * + kelola pool stok marketplace (marketplace_stocks).
 *
 * "Siap jual" = min atas semua komponen bundle dari floor(pool_komponen / qty_komponen).
 * Listing yang belum dipetakan ATAU salah satu komponennya belum punya pool
 * (belum di-seed) menghasilkan null — pemanggil TIDAK boleh push angka (anti push-0).
 *
 * Push ke marketplace + resolve item_id/variation_id + seed awal pool = task berikutnya.
 */
class MarketplaceStockService
{
    public function __construct(private ShopeeClient $shopee, private TikTokClient $tiktok) {}

    /**
     * Komponen produk (bundle-aware) untuk satu seller_sku pada satu channel.
     *
     * @return array<int, array{product_id:int, qty:int}>
     */
    public function componentsFor(string $channel, string $sku): array
    {
        $maps = $channel === 'tiktok'
            ? TiktokSkuMap::where('tiktok_sku', $sku)->get(['product_id', 'qty'])
            : ShopeeSkuMap::where('shopee_sku', $sku)->get(['product_id', 'qty']);

        if ($maps->isNotEmpty()) {
            return $maps->map(fn ($m) => ['product_id' => (int) $m->product_id, 'qty' => max(1, (int) $m->qty)])->all();
        }

        $p = Product::where('sku', $sku)->first(); // fallback: SKU = Product.sku (×1)

        return $p ? [['product_id' => $p->id, 'qty' => 1]] : [];
    }

    /**
     * Jumlah siap jual untuk satu listing, atau null bila belum boleh di-push
     * (belum dipetakan, atau ada komponen yang pool-nya belum di-seed).
     */
    public function availableForListing(MarketplaceListing $l): ?int
    {
        $components = $this->componentsFor($l->channel, $l->seller_sku);
        if (! $components) {
            return null;
        }

        $avail = null;
        foreach ($components as $c) {
            $pool = MarketplaceStock::where('product_id', $c['product_id'])->first();
            if (! $pool) {
                return null; // pool belum di-seed/di-set → jangan push (pengaman anti-0)
            }
            $canMake = intdiv(max(0, (int) $pool->quantity), $c['qty']);
            $avail = $avail === null ? $canMake : min($avail, $canMake);
        }

        return $avail;
    }

    /**
     * Geser pool sebesar $delta (mis. mirror dari mutasi stok HQ). No-op bila
     * pool belum pernah di-set — delta TIDAK boleh membuat baris pool baru.
     */
    public function adjustPool(Product $product, int $delta): void
    {
        $row = MarketplaceStock::where('product_id', $product->id)->first();
        if (! $row) {
            return; // pool belum ada → mirror tak berlaku (jangan buat via delta)
        }
        $row->update(['quantity' => max(0, (int) $row->quantity + $delta)]);
    }

    /**
     * Set/seed pool ke nilai absolut $qty dan stempel seeded_at = now().
     */
    public function setPool(Product $product, int $qty): MarketplaceStock
    {
        return MarketplaceStock::updateOrCreate(
            ['product_id' => $product->id],
            ['quantity' => max(0, $qty), 'seeded_at' => now()],
        );
    }

    /**
     * Terapkan delta dari order marketplace ke pool, tapi HANYA bila pool sudah
     * di-seed dan order-nya terjadi setelah seeded_at (order lama diabaikan).
     */
    public function applyOrderDelta(Product $product, int $delta, Carbon $orderCreatedAt): void
    {
        $row = MarketplaceStock::where('product_id', $product->id)->first();
        if (! $row || $row->seeded_at === null || $orderCreatedAt->lt($row->seeded_at)) {
            return;
        }
        $row->update(['quantity' => max(0, (int) $row->quantity + $delta)]);
    }
}
