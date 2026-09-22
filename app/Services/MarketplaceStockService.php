<?php

namespace App\Services;

use App\Models\MarketplaceChannelOverride;
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
     * (belum dipetakan, atau ada komponen yang stok efektifnya — override ATAU
     * master — belum di-seed). Channel-aware lewat channelStock() per komponen
     * (Fase 1.5): kalau ada override (product,channel), override itu yang dipakai;
     * kalau tidak, jatuh balik ke pool Master.
     */
    public function availableForListing(MarketplaceListing $l): ?int
    {
        $components = $this->componentsFor($l->channel, $l->seller_sku);
        if (! $components) {
            return null;
        }

        $avail = null;
        foreach ($components as $c) {
            $stock = $this->channelStock($c['product_id'], $l->channel);
            if ($stock === null) {
                return null; // master & override dua-duanya kosong → anti push-0
            }
            $canMake = intdiv(max(0, $stock), $c['qty']);
            $avail = $avail === null ? $canMake : min($avail, $canMake);
        }

        return $avail;
    }

    /**
     * Stok efektif satu produk pada satu channel: override (product,channel) bila
     * ada, jika tidak jatuh balik ke pool Master, jika keduanya belum di-seed
     * hasilnya null (dipakai availableForListing() sbg pengaman anti push-0).
     */
    public function channelStock(int $productId, string $channel): ?int
    {
        $override = MarketplaceChannelOverride::where('product_id', $productId)->where('channel', $channel)->first();
        if ($override) {
            return (int) $override->quantity;
        }

        $master = MarketplaceStock::where('product_id', $productId)->first();

        return $master ? (int) $master->quantity : null;
    }

    /**
     * Set/seed override channel ke nilai absolut $qty (clamp ≥0) dan stempel
     * seeded_at = now(). Tak menyentuh pool Master maupun channel lain.
     */
    public function setChannelOverride(Product $product, string $channel, int $qty): MarketplaceChannelOverride
    {
        return MarketplaceChannelOverride::updateOrCreate(
            ['product_id' => $product->id, 'channel' => $channel],
            ['quantity' => max(0, $qty), 'seeded_at' => now()],
        );
    }

    /**
     * Hapus override (product,channel) — channelStock() jatuh balik ke Master lagi.
     */
    public function clearChannelOverride(Product $product, string $channel): void
    {
        MarketplaceChannelOverride::where('product_id', $product->id)->where('channel', $channel)->delete();
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
     * Terapkan delta dari order marketplace channel-aware (Fase 1.5): kalau
     * (product,channel) punya override, delta itu yang digeser (Master TIDAK
     * disentuh); kalau tidak, jatuh balik ke pool Master — persis Fase 1.
     * Di kedua jalur, HANYA bila baris tujuan sudah di-seed dan order-nya
     * terjadi setelah seeded_at-nya (order lama diabaikan).
     */
    public function applyOrderDelta(Product $product, string $channel, int $delta, Carbon $orderCreatedAt): void
    {
        $override = MarketplaceChannelOverride::where('product_id', $product->id)->where('channel', $channel)->first();
        if ($override) {
            if ($override->seeded_at === null || $orderCreatedAt->lt($override->seeded_at)) {
                return;
            }
            $override->update(['quantity' => max(0, (int) $override->quantity + $delta)]);

            return;
        }

        $row = MarketplaceStock::where('product_id', $product->id)->first();
        if (! $row || $row->seeded_at === null || $orderCreatedAt->lt($row->seeded_at)) {
            return;
        }
        $row->update(['quantity' => max(0, (int) $row->quantity + $delta)]);
    }

    /**
     * Tautkan seller_sku (listing belum terpetakan) ke produk master langsung
     * dari halaman Stok — bikin/isi baris peta SKU channel-nya lalu lepaskan
     * status 'unmapped' listing terkait. `firstOrCreate` biar aman kalau
     * komponen sudah ada (bundle multi-komponen = beberapa kali tautkan produk
     * berbeda utk seller_sku yang sama).
     */
    public function linkSku(string $channel, string $sellerSku, int $productId, int $qty): void
    {
        if ($channel === 'tiktok') {
            TiktokSkuMap::firstOrCreate(['tiktok_sku' => $sellerSku, 'product_id' => $productId], ['qty' => max(1, $qty)]);
        } else {
            ShopeeSkuMap::firstOrCreate(['shopee_sku' => $sellerSku, 'product_id' => $productId], ['qty' => max(1, $qty)]);
        }
        MarketplaceListing::where('channel', $channel)->where('seller_sku', $sellerSku)
            ->where('last_status', 'unmapped')->update(['last_status' => null]);
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

    /**
     * Seed awal pool "Stok Marketplace" per produk dari stok TikTok SAAT INI —
     * hanya untuk listing 1:1 (satu komponen, qty 1); bundle dilewati (butuh
     * keputusan manual, bukan auto-seed). Lalu pushAll() supaya Shopee (dan
     * TikTok) ikut disamakan dgn nilai awal ini.
     *
     * Anti push-0 (load-bearing): kalau quantity SKU tak terbaca dari respons
     * (key 'inventory' absen), JANGAN setPool(..., 0) — pushAll() di akhir akan
     * mengirim angka pool ke listing LIVE, jadi men-set 0 dari data yang tak
     * lengkap bisa menge-nol-kan listing yang sebenarnya masih ada stoknya.
     * Produk begitu dibiarkan belum di-seed (pool tetap kosong) → tetap
     * fail-safe lewat availableForListing()/pushListing() (lihat kelas ini).
     *
     * @return array{seeded:int, skipped:int}
     */
    public function seedFromTiktok(): array
    {
        $c = $this->tiktokConn();
        if (! $c) {
            return ['seeded' => 0, 'skipped' => 0];
        }

        $tok = $this->tiktokToken($c);
        $seeded = $skipped = 0;
        $pageToken = '';

        // NB: sama seperti resolveTiktok() — TikTokClient::request() sudah unwrap
        // ke $json['data'], jadi TANPA prefiks 'data.' di path data_get() bawah ini.
        for ($guard = 0; $guard < 200; $guard++) {
            $res = $this->tiktok->searchProducts($tok, $c->shop_cipher, 50, $pageToken);
            foreach (data_get($res, 'products', []) as $prod) {
                foreach ($prod['skus'] ?? [] as $sku) {
                    $sellerSku = (string) data_get($sku, 'seller_sku', '');
                    if ($sellerSku === '') {
                        continue;
                    }

                    $components = $this->componentsFor('tiktok', $sellerSku);
                    // hanya 1:1 (satu komponen, qty 1) yang di-seed otomatis — bundle dilewati
                    if (count($components) !== 1 || $components[0]['qty'] !== 1) {
                        $skipped++;

                        continue;
                    }

                    // qty absen (bukan cuma 0) -> data tak lengkap, lewati (anti push-0 di atas)
                    $qty = data_get($sku, 'inventory.0.quantity');
                    if ($qty === null) {
                        $skipped++;

                        continue;
                    }

                    $product = Product::find($components[0]['product_id']);
                    if (! $product) {
                        $skipped++;

                        continue;
                    }

                    $this->setPool($product, (int) $qty);
                    $seeded++;
                }
            }
            $pageToken = (string) data_get($res, 'next_page_token', '');
            if ($pageToken === '') {
                break;
            }
            if ($guard === 199) {
                Log::warning('[marketplace:seed] TikTok mentok 200 halaman — seed mungkin belum lengkap.');
            }
        }

        $this->pushAll(); // samakan Shopee (dan TikTok) dgn nilai awal

        return compact('seeded', 'skipped');
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

    // ---- Push stok ke channel + catat hasil ----

    /**
     * Push "siap jual" satu listing ke channel-nya & catat hasilnya di baris listing.
     * `unmapped` = BENAR2 belum terpetakan (tak ada SKU-map maupun Product.sku yang
     * cocok) — TIDAK mengirim HTTP apa pun. `skip` = belum ter-resolve (item_id
     * kosong), (tanpa force) angkanya sama dengan push terakhir, ATAU sudah
     * dipetakan tapi pool salah satu komponennya belum di-seed (anti push-0, lihat
     * availableForListing()) — kasus terakhir ini sengaja TIDAK direlabel 'unmapped'
     * supaya cron pushDirty tak salah label listing yang sebenarnya sudah mapped.
     * Error channel (RuntimeException dari client) ditangkap di sini supaya satu
     * listing gagal tak menghentikan batch push lainnya.
     */
    public function pushListing(MarketplaceListing $l, bool $force = false): string
    {
        $avail = $this->availableForListing($l);
        if ($avail === null) {
            // null punya 2 sebab: benar2 tak dipetakan (SKU-map absen), ATAU sudah
            // dipetakan tapi pool salah satu komponennya belum di-seed. Cuma sebab
            // PERTAMA yang boleh menandai 'unmapped' — sebab kedua cuma menunda push
            // (pool bakal ke-seed lewat resolve→seed window) & TIDAK boleh direlabel,
            // supaya cron pushDirty 5 menit tak salah label listing yang sudah mapped.
            if ($this->componentsFor($l->channel, $l->seller_sku) === []) {
                $l->update(['last_status' => 'unmapped']);

                return 'unmapped';
            }

            return 'skip'; // mapped tapi pool belum di-seed — JANGAN direlabel
        }
        if (! $l->item_id) {
            return 'skip'; // belum ter-resolve
        }
        if (! $force && $l->last_pushed_qty === $avail) {
            return 'skip';
        }

        try {
            if ($l->channel === 'tiktok') {
                $c = $this->tiktokConn();
                if (! $c) {
                    throw new \RuntimeException('TikTok belum terhubung');
                }
                $this->tiktok->updateStock($this->tiktokToken($c), $c->shop_cipher, $l->item_id, (string) $l->variation_id, (string) $l->warehouse_id, $avail);
            } else {
                $c = $this->shopeeConn();
                if (! $c) {
                    throw new \RuntimeException('Shopee belum terhubung');
                }
                $this->shopee->updateStock($this->shopeeToken($c), $c->shop_id, (int) $l->item_id, (int) $l->variation_id, $avail);
            }
            $l->update(['last_pushed_qty' => $avail, 'last_status' => 'ok', 'last_error' => null, 'last_pushed_at' => now()]);

            return 'ok';
        } catch (\Throwable $e) {
            $l->update(['last_status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 500)]);

            return 'failed';
        }
    }

    /**
     * Push semua listing (lintas channel) yang salah satu komponennya (bundle-aware)
     * adalah produk ini — dipakai saat stok satu produk berubah & perlu disebar ke
     * tiap listing yang memuatnya. Default force=true supaya perubahan langsung
     * disebar terlepas dari nilai push terakhir.
     *
     * @return array{pushed:int, skipped:int, failed:int}
     */
    public function pushProduct(Product $product, bool $force = true): array
    {
        $listings = collect();
        foreach (MarketplaceListing::all() as $l) {
            foreach ($this->componentsFor($l->channel, $l->seller_sku) as $c) {
                if ($c['product_id'] === $product->id) {
                    $listings->push($l);
                    break;
                }
            }
        }

        $out = ['pushed' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($listings as $l) {
            $this->tally($out, $this->pushListing($l, $force));
        }

        return $out;
    }

    /**
     * Push hanya listing yang sudah ter-resolve DAN angkanya berubah sejak push
     * terakhir (dipakai jadwal rutin — hemat panggilan API channel).
     *
     * @return array{pushed:int, skipped:int, failed:int}
     */
    public function pushDirty(): array
    {
        return $this->pushEach(false);
    }

    /**
     * Push SEMUA listing yang sudah ter-resolve, paksa walau angkanya belum
     * berubah (dipakai mis. setelah insiden/keraguan sinkron).
     *
     * @return array{pushed:int, skipped:int, failed:int}
     */
    public function pushAll(): array
    {
        return $this->pushEach(true);
    }

    /** @return array{pushed:int, skipped:int, failed:int} */
    private function pushEach(bool $force): array
    {
        $out = ['pushed' => 0, 'skipped' => 0, 'failed' => 0];
        foreach (MarketplaceListing::whereNotNull('item_id')->get() as $l) {
            $this->tally($out, $this->pushListing($l, $force));
        }

        return $out;
    }

    /** @param  array{pushed:int, skipped:int, failed:int}  $out */
    private function tally(array &$out, string $result): void
    {
        if ($result === 'ok') {
            $out['pushed']++;
        } elseif ($result === 'failed') {
            $out['failed']++;
        } else {
            $out['skipped']++;
        }
    }
}
