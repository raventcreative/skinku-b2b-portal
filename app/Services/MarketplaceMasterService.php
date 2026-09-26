<?php

namespace App\Services;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\MarketplaceMasterChannel;
use App\Models\Product;
use App\Models\ShopeeConnection;
use App\Models\TiktokConnection;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Engine "Produk Master E-commerce" (ala Desty): tiap unit jualan (satuan/varian/
 * bundle) = 1 master dgn stok+harga di-set langsung, tertaut ke listing TikTok/
 * Shopee, override per-channel. TERPISAH TOTAL dari stok HQ.
 */
class MarketplaceMasterService
{
    public function __construct(private ShopeeClient $shopee, private TikTokClient $tiktok) {}

    // ---- Nilai efektif per (master, channel) ----

    public function effectiveStock(MarketplaceMaster $m, string $channel): ?int
    {
        $ch = $m->channels->firstWhere('channel', $channel)
            ?? MarketplaceMasterChannel::where('master_id', $m->id)->where('channel', $channel)->first();
        if ($ch && $ch->stock !== null) {
            return (int) $ch->stock;
        }

        return $m->base_stock !== null ? (int) $m->base_stock : null;
    }

    public function effectivePrice(MarketplaceMaster $m, string $channel): ?float
    {
        $ch = $m->channels->firstWhere('channel', $channel)
            ?? MarketplaceMasterChannel::where('master_id', $m->id)->where('channel', $channel)->first();
        if ($ch && $ch->price !== null) {
            return (float) $ch->price;
        }

        return $m->base_price !== null ? (float) $m->base_price : null;
    }

    // ---- Setter Master ----

    public function setMasterStock(MarketplaceMaster $m, int $qty): void
    {
        $m->update(['base_stock' => max(0, $qty), 'seeded_at' => now()]);
    }

    public function setMasterPrice(MarketplaceMaster $m, float $price): void
    {
        $m->update(['base_price' => max(0, $price)]);
    }

    // ---- Setter override channel ----

    public function setChannelStock(MarketplaceMaster $m, string $channel, int $qty): MarketplaceMasterChannel
    {
        return MarketplaceMasterChannel::updateOrCreate(
            ['master_id' => $m->id, 'channel' => $channel],
            ['stock' => max(0, $qty), 'seeded_at' => now()],
        );
    }

    public function setChannelPrice(MarketplaceMaster $m, string $channel, float $price): MarketplaceMasterChannel
    {
        return MarketplaceMasterChannel::updateOrCreate(
            ['master_id' => $m->id, 'channel' => $channel],
            ['price' => max(0, $price)],
        );
    }

    /** Kembalikan satu field channel ke "ikut Master"; hapus baris bila stock & price dua-duanya null. */
    public function ikutMaster(MarketplaceMaster $m, string $channel, string $field): void
    {
        $row = MarketplaceMasterChannel::where('master_id', $m->id)->where('channel', $channel)->first();
        if (! $row) {
            return;
        }
        $row->update([$field => null]);
        if ($row->stock === null && $row->price === null) {
            $row->delete();
        }
    }

    // ---- Helper koneksi & token (SALINAN transisi dari MarketplaceStockService) ----

    private function tiktokConn(): ?TiktokConnection
    {
        return TiktokConnection::latest('id')->first();
    }

    private function shopeeConn(): ?ShopeeConnection
    {
        return ShopeeConnection::latest('id')->first();
    }

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

    private function tiktokExpiry(mixed $v): ?Carbon
    {
        if (! $v) {
            return null;
        }
        $v = (int) $v;

        return $v > 1_000_000_000 ? Carbon::createFromTimestamp($v) : now()->addSeconds($v);
    }

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

    private function shopeeExpiry(mixed $expireIn): ?Carbon
    {
        return $expireIn ? now()->addSeconds((int) $expireIn) : null;
    }

    // ---- Resolve listing dari channel + auto-buat Master + tautkan/gabung ----

    /**
     * Isi/segarkan marketplace_listings dari channel (item_id/variation_id/
     * warehouse_id) & pastikan tiap listing punya master (auto-buat kalau
     * belum ada baris marketplace_masters ber-master_sku = seller_sku-nya).
     * TERPISAH dari MarketplaceStockService::resolveListings() (SkuMap/HQ) —
     * di sini tak ada konsep 'unmapped', semua listing selalu dapat master.
     *
     * @return array{found:int, mastered:int}
     */
    public function resolveListings(string $channel): array
    {
        return $channel === 'tiktok' ? $this->resolveTiktok() : $this->resolveShopee();
    }

    /**
     * Cari master ber-master_sku = $sellerSku, atau buat baru (name = $title,
     * fallback ke $sellerSku kalau title kosong; product_id opsional lewat
     * Product.sku yang sama). Master yang SUDAH ADA tak disentuh (name/product_id
     * lama dipertahankan) — resolve bukan tugasnya menimpa master existing.
     */
    public function findOrCreateMaster(string $sellerSku, ?string $title): MarketplaceMaster
    {
        $m = MarketplaceMaster::firstOrNew(['master_sku' => $sellerSku]);
        if (! $m->exists) {
            $m->name = $title !== null && $title !== '' ? $title : $sellerSku;
            $m->product_id = Product::where('sku', $sellerSku)->value('id'); // opsional; boleh null
            $m->save();
        }

        return $m;
    }

    /**
     * Tautkan/pindahkan satu listing ke master lain (gabung ke $masterId yang
     * sudah ada), atau — kalau $masterId null — buat master baru ber-SKU
     * $newSku (fallback ke seller_sku listing) & nama $newName lalu tautkan.
     */
    public function tautkanListing(MarketplaceListing $listing, ?int $masterId, ?string $newSku = null, ?string $newName = null): void
    {
        if ($masterId !== null) {
            $listing->update(['master_id' => $masterId]);

            return;
        }
        $sku = $newSku !== null && $newSku !== '' ? $newSku : $listing->seller_sku;
        $m = $this->findOrCreateMaster($sku, $newName ?? $listing->title);
        $listing->update(['master_id' => $m->id]);
    }

    // ---- Push stok & harga (aditif; independen per listing, anti-push null) ----

    /** Push stok & harga efektif satu listing (via master+channel). Kedua field independen, anti-push null. */
    public function pushListing(MarketplaceListing $l, bool $force = false): array
    {
        $master = $l->master_id ? $l->master : null;
        if (! $master || ! $l->item_id) {
            return ['stock' => 'skip', 'price' => 'skip'];
        }
        $stock = $this->effectiveStock($master, $l->channel);
        $price = $this->effectivePrice($master, $l->channel);

        return [
            'stock' => $this->pushStock($l, $stock, $force),
            'price' => $this->pushPrice($l, $price, $force),
        ];
    }

    private function pushStock(MarketplaceListing $l, ?int $stock, bool $force): string
    {
        if ($stock === null) {
            return 'skip';
        }
        if (! $force && $l->last_pushed_qty === $stock) {
            return 'skip';
        }
        try {
            if ($l->channel === 'tiktok') {
                $c = $this->tiktokConn() ?? throw new \RuntimeException('TikTok belum terhubung');
                $this->tiktok->updateStock($this->tiktokToken($c), $c->shop_cipher, $l->item_id, (string) $l->variation_id, (string) $l->warehouse_id, $stock);
            } else {
                $c = $this->shopeeConn() ?? throw new \RuntimeException('Shopee belum terhubung');
                $this->shopee->updateStock($this->shopeeToken($c), $c->shop_id, (int) $l->item_id, (int) $l->variation_id, $stock);
            }
            $l->update(['last_pushed_qty' => $stock, 'last_status' => 'ok', 'last_error' => null, 'last_pushed_at' => now()]);

            return 'ok';
        } catch (\Throwable $e) {
            $l->update(['last_status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 500)]);

            return 'failed';
        }
    }

    private function pushPrice(MarketplaceListing $l, ?float $price, bool $force): string
    {
        if ($price === null) {
            return 'skip';
        }
        if (! $force && $l->last_pushed_price !== null && (float) $l->last_pushed_price === $price) {
            return 'skip';
        }
        try {
            if ($l->channel === 'tiktok') {
                $c = $this->tiktokConn() ?? throw new \RuntimeException('TikTok belum terhubung');
                $this->tiktok->updatePrice($this->tiktokToken($c), $c->shop_cipher, $l->item_id, (string) $l->variation_id, $price);
            } else {
                $c = $this->shopeeConn() ?? throw new \RuntimeException('Shopee belum terhubung');
                $this->shopee->updatePrice($this->shopeeToken($c), $c->shop_id, (int) $l->item_id, (int) $l->variation_id, $price);
            }
            $l->update(['last_pushed_price' => $price, 'last_price_status' => 'ok', 'last_price_error' => null, 'last_price_pushed_at' => now()]);

            return 'ok';
        } catch (\Throwable $e) {
            $l->update(['last_price_status' => 'failed', 'last_price_error' => mb_substr($e->getMessage(), 0, 500)]);

            return 'failed';
        }
    }

    public function pushMaster(MarketplaceMaster $m, bool $force = true): array
    {
        $out = ['pushed' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($m->listings()->whereNotNull('item_id')->get() as $l) {
            $this->tallyPush($out, $this->pushListing($l, $force));
        }

        return $out;
    }

    public function pushDirty(): array
    {
        return $this->pushEach(false);
    }

    public function pushAll(): array
    {
        return $this->pushEach(true);
    }

    private function pushEach(bool $force): array
    {
        $out = ['pushed' => 0, 'skipped' => 0, 'failed' => 0];
        foreach (MarketplaceListing::whereNotNull('item_id')->whereNotNull('master_id')->get() as $l) {
            $this->tallyPush($out, $this->pushListing($l, $force));
        }

        return $out;
    }

    /** Hitung stok & harga sbg dua unit terpisah: ok→pushed, failed→failed, skip→skipped. */
    private function tallyPush(array &$out, array $res): void
    {
        foreach (['stock', 'price'] as $field) {
            match ($res[$field]) {
                'ok' => $out['pushed']++,
                'failed' => $out['failed']++,
                default => $out['skipped']++,
            };
        }
    }

    // ---- Seed awal dari TikTok & cermin order (aditif; HQ TAK disentuh) ----

    public function seedFromTiktok(): array
    {
        $c = $this->tiktokConn();
        if (! $c) {
            return ['seeded' => 0, 'skipped' => 0];
        }
        $tok = $this->tiktokToken($c);
        $seeded = $skipped = 0;
        $pageToken = '';

        // TikTokClient::request() sudah unwrap 'data' → path TANPA prefiks 'data.'.
        for ($guard = 0; $guard < 200; $guard++) {
            $res = $this->tiktok->searchProducts($tok, $c->shop_cipher, 50, $pageToken);
            foreach (data_get($res, 'products', []) as $prod) {
                $title = data_get($prod, 'title');
                foreach ($prod['skus'] ?? [] as $sku) {
                    $sellerSku = (string) data_get($sku, 'seller_sku', '');
                    if ($sellerSku === '') {
                        continue;
                    }
                    $qty = data_get($sku, 'inventory.0.quantity'); // absen → data tak lengkap
                    if ($qty === null) {
                        $skipped++;

                        continue;
                    }
                    // SEMUA unit (termasuk bundle) → tiap seller_sku = 1 master.
                    $m = $this->findOrCreateMaster($sellerSku, $title !== null ? (string) $title : null);
                    $this->setMasterStock($m, (int) $qty);
                    $seeded++;
                }
            }
            $pageToken = (string) data_get($res, 'next_page_token', '');
            if ($pageToken === '') {
                break;
            }
        }

        $this->pushAll();

        return compact('seeded', 'skipped');
    }

    /** Cermin order marketplace → turunkan/kembalikan bucket efektif stok master (HQ TAK disentuh). */
    public function applyOrderDelta(MarketplaceListing $listing, int $delta, Carbon $orderCreatedAt): void
    {
        if (! $listing->master_id) {
            return;
        }
        $master = $listing->master;
        if (! $master) {
            return;
        }
        $channel = $listing->channel;
        $override = MarketplaceMasterChannel::where('master_id', $master->id)->where('channel', $channel)->first();

        if ($override && $override->stock !== null) {
            if ($override->seeded_at === null || $orderCreatedAt->lt($override->seeded_at)) {
                return;
            }
            $override->update(['stock' => max(0, (int) $override->stock + $delta)]);

            return;
        }

        if ($master->base_stock === null || $master->seeded_at === null || $orderCreatedAt->lt($master->seeded_at)) {
            return;
        }
        $master->update(['base_stock' => max(0, (int) $master->base_stock + $delta)]);
    }

    /**
     * Upsert satu baris marketplace_listings by (channel, seller_sku) lalu
     * pastikan punya master (auto-buat via findOrCreateMaster). Listing yang
     * SUDAH tertaut ke master (mis. hasil tautkanListing manual, sering ke
     * master ber-master_sku BEDA dari seller_sku listing ini — itu maksudnya
     * "gabung") SAMA SEKALI TAK disentuh: TAK ada lookup/pembuatan master
     * apa pun dijalankan. Kalau findOrCreateMaster(seller_sku) dipanggil
     * tanpa syarat di sini, tiap resolve ulang bakal bikin master ORPHAN baru
     * ber-master_sku = seller_sku listing ini (krn master_sku itu tak
     * ditemukan — listing-nya sudah "pindah rumah" ke master lain) — orphan
     * itu permanen krn tak ada yang mem-prune master. Makanya guard dulu.
     */
    private function upsertListing(string $channel, string $sellerSku, string $itemId, string $variationId, ?string $warehouseId, ?string $title): MarketplaceListing
    {
        $l = MarketplaceListing::updateOrCreate(
            ['channel' => $channel, 'seller_sku' => $sellerSku],
            ['item_id' => $itemId, 'variation_id' => $variationId, 'warehouse_id' => $warehouseId, 'title' => $title, 'resolved_at' => now()],
        );
        if ($l->master_id === null) {
            $master = $this->findOrCreateMaster($sellerSku, $title);
            $l->update(['master_id' => $master->id]);
        }

        return $l;
    }

    /**
     * NB: TikTokClient::request() sudah unwrap ke $json['data'], jadi TANPA
     * prefiks 'data.' di path data_get() bawah ini (sama spt
     * MarketplaceStockService::resolveTiktok()).
     */
    private function resolveTiktok(): array
    {
        $c = $this->tiktokConn();
        if (! $c) {
            return ['found' => 0, 'mastered' => 0];
        }
        $tok = $this->tiktokToken($c);
        // Gudang SALES diutamakan sbg default warehouse_id listing; kalau tak ada
        // yang bertipe itu (atau field/enum-nya beda dari dugaan), JATUH BALIK ke
        // gudang pertama drpd membiarkan warehouse_id null utk SEMUA listing —
        // spt MarketplaceStockService::resolveTiktok() (proven) yg pakai warehouses.0.id.
        $warehouses = data_get($this->tiktok->getWarehouses($tok, $c->shop_cipher), 'warehouses', []);
        $warehouseId = null;
        foreach ($warehouses as $w) {
            if (($w['type'] ?? null) === 'SALES_WAREHOUSE') {
                $warehouseId = (string) $w['id'];
                break;
            }
        }
        if ($warehouseId === null && $warehouses) {
            $warehouseId = (string) data_get($warehouses[0], 'id');
        }

        $found = $mastered = 0;
        $pageToken = '';
        for ($guard = 0; $guard < 200; $guard++) {
            $res = $this->tiktok->searchProducts($tok, $c->shop_cipher, 50, $pageToken);
            foreach (data_get($res, 'products', []) as $prod) {
                $pid = (string) data_get($prod, 'id', '');
                $title = data_get($prod, 'title');
                foreach ($prod['skus'] ?? [] as $sku) {
                    $sellerSku = (string) data_get($sku, 'seller_sku', '');
                    if ($sellerSku === '') {
                        continue;
                    }
                    $this->upsertListing('tiktok', $sellerSku, $pid, (string) data_get($sku, 'id', ''), $warehouseId, $title !== null ? (string) $title : null);
                    $found++;
                    $mastered++;
                }
            }
            $pageToken = (string) data_get($res, 'next_page_token', '');
            if ($pageToken === '') {
                break;
            }
            if ($guard === 199) {
                Log::warning('[mp-master:resolve] TikTok mentok 200 halaman.');
            }
        }

        return compact('found', 'mastered');
    }

    /**
     * Paging & bentuk respons IDENTIK dgn MarketplaceStockService::resolveShopee():
     * get_item_base_info di-batch SEKALI per halaman (bukan per-item) & get_model_list
     * hanya dipanggil kalau item punya varian (`has_model`) — hemat panggilan API
     * persis spt service lama.
     */
    private function resolveShopee(): array
    {
        $c = $this->shopeeConn();
        if (! $c) {
            return ['found' => 0, 'mastered' => 0];
        }

        $tok = $this->shopeeToken($c);
        $found = $mastered = 0;
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
                        foreach ($models as $mo) {
                            $sellerSku = (string) ($mo['model_sku'] ?? '');
                            if ($sellerSku === '') {
                                continue;
                            }
                            $this->upsertListing('shopee', $sellerSku, $itemId, (string) ($mo['model_id'] ?? '0'), null, $title);
                            $found++;
                            $mastered++;
                        }
                    } else {
                        $sellerSku = (string) ($info['item_sku'] ?? '');
                        if ($sellerSku === '') {
                            continue;
                        }
                        $this->upsertListing('shopee', $sellerSku, $itemId, '0', null, $title);
                        $found++;
                        $mastered++;
                    }
                }
            }

            $offset += count($items);
            $hasNext = (bool) data_get($res, 'response.has_next_page', false);
            if (! $hasNext || $items === []) {
                break;
            }
            if ($guard === 199) {
                Log::warning('[mp-master:resolve] Shopee mentok 200 halaman.');
            }
        }

        return compact('found', 'mastered');
    }
}
