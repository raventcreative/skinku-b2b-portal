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

    /**
     * Upsert satu baris marketplace_listings by (channel, seller_sku) lalu
     * pastikan punya master (auto-buat via findOrCreateMaster). Listing yang
     * SUDAH tertaut ke master (mis. hasil tautkanListing manual) TAK dipindah
     * paksa ke master lain — resolve tak boleh menimpa tautan manual.
     */
    private function upsertListing(string $channel, string $sellerSku, string $itemId, string $variationId, ?string $warehouseId, ?string $title): MarketplaceListing
    {
        $l = MarketplaceListing::updateOrCreate(
            ['channel' => $channel, 'seller_sku' => $sellerSku],
            ['item_id' => $itemId, 'variation_id' => $variationId, 'warehouse_id' => $warehouseId, 'title' => $title, 'resolved_at' => now()],
        );
        $master = $this->findOrCreateMaster($sellerSku, $title);
        if ($l->master_id === null) {
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
        // Gudang SALES pertama sbg default warehouse_id listing.
        $warehouseId = null;
        foreach (data_get($this->tiktok->getWarehouses($tok, $c->shop_cipher), 'warehouses', []) as $w) {
            if (($w['type'] ?? null) === 'SALES_WAREHOUSE') {
                $warehouseId = (string) $w['id'];
                break;
            }
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
