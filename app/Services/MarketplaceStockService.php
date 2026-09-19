<?php

namespace App\Services;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceStock;
use App\Models\Product;
use App\Models\ShopeeConnection;
use App\Models\ShopeeSkuMap;
use App\Models\TiktokConnection;
use App\Models\TiktokSkuMap;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

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

    // ---- Resolve listing (isi item_id/variation_id/warehouse_id dari channel) ----

    private function tiktokConn(): ?TiktokConnection
    {
        return TiktokConnection::latest('id')->first();
    }

    private function shopeeConn(): ?ShopeeConnection
    {
        return ShopeeConnection::latest('id')->first();
    }

    /**
     * Access token TikTok yang masih valid (refresh otomatis kalau mau expire).
     * SALINAN `TikTokSyncService::freshToken()` — sengaja tak inject TikTokSyncService
     * di sini supaya tak bikin siklus DI (service itu nanti butuh kelas ini juga).
     */
    private function tiktokToken(TiktokConnection $c): string
    {
        if (! $c->accessExpiringSoon()) {
            return (string) $c->access_token;
        }
        $t = $this->tiktok->refreshToken($c->refresh_token);
        $c->update([
            'access_token' => $t['access_token'],
            'refresh_token' => $t['refresh_token'] ?? $c->refresh_token,
            'access_expires_at' => $this->tiktokExpiry($t['access_token_expire_in'] ?? null),
            'refresh_expires_at' => $this->tiktokExpiry($t['refresh_token_expire_in'] ?? null),
        ]);

        return (string) $t['access_token'];
    }

    /** SALINAN `TikTokSyncService::toTime()` — epoch detik (>1 milyar) ATAU detik-dari-sekarang. */
    private function tiktokExpiry(mixed $v): ?Carbon
    {
        if (! $v) {
            return null;
        }
        $v = (int) $v;

        return $v > 1_000_000_000 ? Carbon::createFromTimestamp($v) : now()->addSeconds($v);
    }

    /**
     * Access token Shopee yang masih valid (token cuma ~4 jam).
     * SALINAN `ShopeeSyncService::freshToken()` (alasan sama seperti tiktokToken()).
     */
    private function shopeeToken(ShopeeConnection $c): string
    {
        if (! $c->accessExpiringSoon()) {
            return (string) $c->access_token;
        }
        $t = $this->shopee->refreshToken($c->refresh_token, $c->shop_id);
        $c->update([
            'access_token' => $t['access_token'],
            'refresh_token' => $t['refresh_token'] ?? $c->refresh_token,
            'access_expires_at' => $this->shopeeExpiry($t['expire_in'] ?? null),
        ]);

        return (string) $t['access_token'];
    }

    /** SALINAN `ShopeeSyncService::toTime()` — Shopee kirim expire_in sbg DETIK-dari-sekarang. */
    private function shopeeExpiry(mixed $expireIn): ?Carbon
    {
        return $expireIn ? now()->addSeconds((int) $expireIn) : null;
    }

    /**
     * Isi/segarkan marketplace_listings dengan item_id/variation_id/warehouse_id dari
     * channel (dibutuhkan sebelum bisa push stok — task berikutnya). Listing yang
     * seller_sku-nya tak ada di peta SKU (& bukan Product.sku) ditandai 'unmapped'.
     * Tak menyentuh pool stok maupun push apa pun.
     *
     * @return array{found:int, mapped:int, unmapped:int}
     */
    public function resolveListings(string $channel): array
    {
        return $channel === 'tiktok' ? $this->resolveTiktok() : $this->resolveShopee();
    }

    private function resolveTiktok(): array
    {
        $c = $this->tiktokConn();
        if (! $c) {
            return ['found' => 0, 'mapped' => 0, 'unmapped' => 0];
        }

        $tok = $this->tiktokToken($c);
        // NB: TikTokClient::request() sudah unwrap ke $json['data'], jadi TANPA prefiks 'data.' di sini.
        $wh = data_get($this->tiktok->getWarehouses($tok, $c->shop_cipher), 'warehouses.0.id');

        $found = $mapped = $unmapped = 0;
        $pageToken = '';
        for ($guard = 0; $guard < 200; $guard++) {
            $res = $this->tiktok->searchProducts($tok, $c->shop_cipher, 50, $pageToken);
            foreach (data_get($res, 'products', []) as $prod) {
                foreach ($prod['skus'] ?? [] as $sku) {
                    $sellerSku = (string) ($sku['seller_sku'] ?? '');
                    if ($sellerSku === '') {
                        continue;
                    }
                    $found++;
                    $isMapped = $this->componentsFor('tiktok', $sellerSku) !== [];
                    $this->upsertListing('tiktok', $sellerSku, (string) $prod['id'], (string) $sku['id'], $wh, $prod['title'] ?? null, $isMapped);
                    $isMapped ? $mapped++ : $unmapped++;
                }
            }
            $pageToken = (string) data_get($res, 'next_page_token', '');
            if ($pageToken === '') {
                break;
            }
            if ($guard === 199) {
                Log::warning('[marketplace:resolve] TikTok mentok 200 halaman — listing mungkin belum lengkap.');
            }
        }

        return compact('found', 'mapped', 'unmapped');
    }

    private function resolveShopee(): array
    {
        $c = $this->shopeeConn();
        if (! $c) {
            return ['found' => 0, 'mapped' => 0, 'unmapped' => 0];
        }

        $tok = $this->shopeeToken($c);
        $found = $mapped = $unmapped = 0;
        $offset = 0;

        for ($guard = 0; $guard < 200; $guard++) {
            $res = $this->shopee->getItemList($tok, $c->shop_id, $offset, 50);
            $items = data_get($res, 'response.item', []);
            $itemIds = array_values(array_filter(array_map(fn ($i) => (int) ($i['item_id'] ?? 0), $items)));

            if ($itemIds !== []) {
                $base = $this->shopee->getItemBaseInfo($tok, $c->shop_id, $itemIds);
                foreach (data_get($base, 'response.item_list', []) as $info) {
                    $itemId = (string) ($info['item_id'] ?? '');
                    if ($itemId === '') {
                        continue;
                    }
                    $title = $info['item_name'] ?? null;

                    if (! empty($info['has_model'])) {
                        $models = data_get($this->shopee->getModelList($tok, $c->shop_id, (int) $itemId), 'response.model', []);
                        foreach ($models as $m) {
                            $sellerSku = (string) ($m['model_sku'] ?? '');
                            if ($sellerSku === '') {
                                continue;
                            }
                            $found++;
                            $isMapped = $this->componentsFor('shopee', $sellerSku) !== [];
                            $this->upsertListing('shopee', $sellerSku, $itemId, (string) ($m['model_id'] ?? '0'), null, $title, $isMapped);
                            $isMapped ? $mapped++ : $unmapped++;
                        }
                    } else {
                        $sellerSku = (string) ($info['item_sku'] ?? '');
                        if ($sellerSku === '') {
                            continue;
                        }
                        $found++;
                        $isMapped = $this->componentsFor('shopee', $sellerSku) !== [];
                        $this->upsertListing('shopee', $sellerSku, $itemId, '0', null, $title, $isMapped);
                        $isMapped ? $mapped++ : $unmapped++;
                    }
                }
            }

            $offset += count($items);
            $hasNext = (bool) data_get($res, 'response.has_next_page', false);
            if (! $hasNext || $items === []) {
                break;
            }
            if ($guard === 199) {
                Log::warning('[marketplace:resolve] Shopee mentok 200 halaman — listing mungkin belum lengkap.');
            }
        }

        return compact('found', 'mapped', 'unmapped');
    }

    /**
     * Upsert satu baris marketplace_listings by (channel, seller_sku). Kalau masih
     * mapped, last_status YANG SUDAH ADA dipertahankan (mis. 'ok' dari push
     * sebelumnya) — resolve bukan tugasnya menyetel 'ok'; unmapped SELALU menang.
     */
    private function upsertListing(string $channel, string $sellerSku, string $itemId, string $variationId, ?string $warehouseId, ?string $title, bool $isMapped): void
    {
        MarketplaceListing::updateOrCreate(
            ['channel' => $channel, 'seller_sku' => $sellerSku],
            [
                'item_id' => $itemId,
                'variation_id' => $variationId,
                'warehouse_id' => $warehouseId,
                'title' => $title,
                'last_status' => $isMapped
                    ? MarketplaceListing::where('channel', $channel)->where('seller_sku', $sellerSku)->value('last_status')
                    : 'unmapped',
                'resolved_at' => now(),
            ],
        );
    }
}
