<?php

namespace Tests\Feature\MarketplaceStock;

use App\Models\MarketplaceListing;
use App\Models\Product;
use App\Models\ShopeeConnection;
use App\Models\ShopeeSkuMap;
use App\Models\TiktokConnection;
use App\Models\TiktokSkuMap;
use App\Services\MarketplaceStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Task 6: pushListing/pushProduct/pushDirty/pushAll — kirim "siap jual" (available-
 * to-sell) ke channel & catat hasilnya (last_pushed_qty/last_status/last_error/
 * last_pushed_at). unmapped/pool-belum-di-seed & belum-ter-resolve TIDAK boleh
 * mengirim HTTP apa pun (anti push-0 dari Task 3, diteruskan ke sini).
 */
class PushStockTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function product(string $sku): Product
    {
        return Product::create([
            'name' => 'Produk '.(++$this->seq),
            'sku' => $sku,
            'status' => 'active',
            'price_distributor' => 1,
            'price_reseller' => 1,
        ]);
    }

    private function svc(): MarketplaceStockService
    {
        return app(MarketplaceStockService::class);
    }

    private function fakeTiktokConfig(): void
    {
        config()->set('services.tiktok.api_base', 'https://open-api.tiktokglobalshop.com');
        config()->set('services.tiktok.app_key', 'a');
        config()->set('services.tiktok.app_secret', 's');
    }

    private function fakeShopeeConfig(): void
    {
        config()->set('services.shopee.partner_id', 1);
        config()->set('services.shopee.partner_key', 'k');
        config()->set('services.shopee.api_base', 'https://partner.shopeemobile.com');
    }

    private function tiktokConn(): TiktokConnection
    {
        return TiktokConnection::create([
            'shop_id' => 's', 'shop_cipher' => 'c', 'access_token' => 't', 'refresh_token' => 'r',
            'access_expires_at' => now()->addDay(),
        ]);
    }

    private function shopeeConn(): ShopeeConnection
    {
        return ShopeeConnection::create([
            'shop_id' => '123', 'access_token' => 't', 'refresh_token' => 'r',
            'access_expires_at' => now()->addHours(3),
        ]);
    }

    // ---- pushDirty: hanya kirim yang berubah, lalu catat ----

    public function test_push_dirty_hanya_kirim_yang_berubah_lalu_catat(): void
    {
        $this->fakeTiktokConfig();
        $p = $this->product('FM-1');
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        $this->svc()->setPool($p, 40);
        $this->tiktokConn();
        MarketplaceListing::create([
            'channel' => 'tiktok', 'seller_sku' => 'FM-1', 'item_id' => 'PID1',
            'variation_id' => 'SID1', 'warehouse_id' => 'WH1', 'resolved_at' => now(),
        ]);
        Http::fake(['*/inventory/update*' => Http::response(['code' => 0, 'data' => []])]);

        $r1 = $this->svc()->pushDirty();
        $this->assertSame(['pushed' => 1, 'skipped' => 0, 'failed' => 0], $r1);

        $listing = MarketplaceListing::first();
        $this->assertSame(40, $listing->last_pushed_qty);
        $this->assertSame('ok', $listing->last_status);
        $this->assertNull($listing->last_error);
        $this->assertNotNull($listing->last_pushed_at);

        Http::assertSent(function ($req) {
            $b = $req->data();

            return str_contains($req->url(), '/product/202309/products/PID1/inventory/update')
                && $b['skus'][0]['id'] === 'SID1'
                && $b['skus'][0]['inventory'][0]['warehouse_id'] === 'WH1'
                && $b['skus'][0]['inventory'][0]['quantity'] === 40;
        });

        // kedua kali: pool tak berubah -> skip, tak kirim ulang
        $r2 = $this->svc()->pushDirty();
        $this->assertSame(['pushed' => 0, 'skipped' => 1, 'failed' => 0], $r2);
        Http::assertSentCount(1);
    }

    public function test_push_all_memaksa_kirim_ulang_walau_belum_berubah(): void
    {
        $this->fakeTiktokConfig();
        $p = $this->product('FM-1');
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        $this->svc()->setPool($p, 40);
        $this->tiktokConn();
        MarketplaceListing::create([
            'channel' => 'tiktok', 'seller_sku' => 'FM-1', 'item_id' => 'PID1',
            'variation_id' => 'SID1', 'warehouse_id' => 'WH1', 'last_pushed_qty' => 40,
            'last_status' => 'ok', 'resolved_at' => now(),
        ]);
        Http::fake(['*/inventory/update*' => Http::response(['code' => 0, 'data' => []])]);

        $r = $this->svc()->pushAll();

        $this->assertSame(['pushed' => 1, 'skipped' => 0, 'failed' => 0], $r);
        Http::assertSentCount(1);
    }

    // ---- gagal: error channel tercatat, tak menghentikan batch ----

    public function test_push_listing_gagal_mencatat_last_status_dan_last_error(): void
    {
        $this->fakeTiktokConfig();
        $p = $this->product('FM-1');
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        $this->svc()->setPool($p, 40);
        $this->tiktokConn();
        $l = MarketplaceListing::create([
            'channel' => 'tiktok', 'seller_sku' => 'FM-1', 'item_id' => 'PID1',
            'variation_id' => 'SID1', 'warehouse_id' => 'WH1', 'resolved_at' => now(),
        ]);
        Http::fake(['*/inventory/update*' => Http::response(['code' => 123, 'message' => 'SKU tidak ditemukan'])]);

        $result = $this->svc()->pushListing($l, true);

        $this->assertSame('failed', $result);
        $l->refresh();
        $this->assertSame('failed', $l->last_status);
        $this->assertStringContainsString('SKU tidak ditemukan', $l->last_error);
        $this->assertNull($l->last_pushed_qty); // gagal -> tak diubah
    }

    public function test_push_dirty_menghitung_failed_pada_tally(): void
    {
        $this->fakeTiktokConfig();
        $p = $this->product('FM-1');
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        $this->svc()->setPool($p, 40);
        $this->tiktokConn();
        MarketplaceListing::create([
            'channel' => 'tiktok', 'seller_sku' => 'FM-1', 'item_id' => 'PID1',
            'variation_id' => 'SID1', 'warehouse_id' => 'WH1', 'resolved_at' => now(),
        ]);
        Http::fake(['*/inventory/update*' => Http::response(['code' => 123, 'message' => 'boom'])]);

        $r = $this->svc()->pushDirty();

        $this->assertSame(['pushed' => 0, 'skipped' => 0, 'failed' => 1], $r);
    }

    public function test_push_listing_gagal_jelas_ketika_koneksi_tiktok_belum_ada(): void
    {
        $this->fakeTiktokConfig();
        $p = $this->product('FM-1');
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        $this->svc()->setPool($p, 40);
        // sengaja TANPA TiktokConnection
        $l = MarketplaceListing::create([
            'channel' => 'tiktok', 'seller_sku' => 'FM-1', 'item_id' => 'PID1',
            'variation_id' => 'SID1', 'warehouse_id' => 'WH1', 'resolved_at' => now(),
        ]);
        Http::fake();

        $result = $this->svc()->pushListing($l, true);

        $this->assertSame('failed', $result);
        $this->assertStringContainsString('TikTok belum terhubung', $l->fresh()->last_error);
        Http::assertNothingSent();
    }

    // ---- unmapped / pool belum di-set: skip, TAK ADA Http terkirim ----

    /**
     * Mapped (ada SKU-map) TAPI pool komponennya belum di-seed → 'skip', BUKAN
     * 'unmapped'. availableForListing() null di sini punya 2 sebab berbeda: benar2
     * tak dipetakan, ATAU sudah dipetakan tapi pool-nya belum di-seed. Hanya sebab
     * PERTAMA yang boleh menandai listing 'unmapped'; sebab kedua cuma menunda push
     * (pool bakal ke-seed lewat resolve→seed window) — kalau ikut ditandai 'unmapped',
     * cron pushDirty 5 menit bisa salah label listing yang sebenarnya sudah mapped.
     */
    public function test_push_listing_mapped_tapi_pool_belum_diseed_mengembalikan_skip_tanpa_http(): void
    {
        $this->fakeTiktokConfig();
        $p = $this->product('FM-1');
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        $this->tiktokConn();
        // TIDAK setPool() -> availableForListing() null (tapi SUDAH mapped via SKU-map)
        $l = MarketplaceListing::create([
            'channel' => 'tiktok', 'seller_sku' => 'FM-1', 'item_id' => 'PID1',
            'variation_id' => 'SID1', 'warehouse_id' => 'WH1', 'resolved_at' => now(),
        ]);
        Http::fake();

        $result = $this->svc()->pushListing($l);

        $this->assertSame('skip', $result);
        $this->assertNull($l->fresh()->last_status); // TIDAK direlabel 'unmapped'
        Http::assertNothingSent();
    }

    /** Benar2 tak dipetakan (tanpa SKU-map & tanpa Product.sku yang cocok) → tetap 'unmapped'. */
    public function test_push_listing_benar_benar_unmapped_mengembalikan_unmapped_dan_set_last_status(): void
    {
        $this->fakeTiktokConfig();
        $this->tiktokConn();
        // seller_sku tak ada di SKU-map manapun & bukan Product.sku manapun
        $l = MarketplaceListing::create([
            'channel' => 'tiktok', 'seller_sku' => 'ZZZ', 'item_id' => 'PID9',
            'variation_id' => 'SID9', 'warehouse_id' => 'WH1', 'resolved_at' => now(),
        ]);
        Http::fake();

        $result = $this->svc()->pushListing($l);

        $this->assertSame('unmapped', $result);
        $this->assertSame('unmapped', $l->fresh()->last_status);
        Http::assertNothingSent();
    }

    public function test_push_dirty_melewati_listing_unmapped_tanpa_http(): void
    {
        $this->fakeTiktokConfig();
        $this->tiktokConn();
        // seller_sku tak dipetakan & bukan Product.sku manapun
        MarketplaceListing::create([
            'channel' => 'tiktok', 'seller_sku' => 'ZZZ', 'item_id' => 'PID9',
            'variation_id' => 'SID9', 'warehouse_id' => 'WH1', 'resolved_at' => now(),
        ]);
        Http::fake();

        $r = $this->svc()->pushDirty();

        $this->assertSame(['pushed' => 0, 'skipped' => 1, 'failed' => 0], $r);
        Http::assertNothingSent();
    }

    public function test_push_dirty_melewati_listing_mapped_tapi_pool_belum_diseed_tanpa_http(): void
    {
        $this->fakeTiktokConfig();
        $p = $this->product('FM-1');
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        $this->tiktokConn();
        // TIDAK setPool() -> mapped tapi pool belum di-seed
        MarketplaceListing::create([
            'channel' => 'tiktok', 'seller_sku' => 'FM-1', 'item_id' => 'PID1',
            'variation_id' => 'SID1', 'warehouse_id' => 'WH1', 'resolved_at' => now(),
        ]);
        Http::fake();

        $r = $this->svc()->pushDirty();

        $this->assertSame(['pushed' => 0, 'skipped' => 1, 'failed' => 0], $r);
        $this->assertNull(MarketplaceListing::where('seller_sku', 'FM-1')->value('last_status'));
        Http::assertNothingSent();
    }

    public function test_push_listing_belum_resolve_mengembalikan_skip(): void
    {
        $this->fakeTiktokConfig();
        $p = $this->product('FM-1');
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        $this->svc()->setPool($p, 40);
        $this->tiktokConn();
        $l = MarketplaceListing::create([
            'channel' => 'tiktok', 'seller_sku' => 'FM-1', // item_id masih null: belum ter-resolve
        ]);
        Http::fake();

        $result = $this->svc()->pushListing($l, true);

        $this->assertSame('skip', $result);
        $this->assertNull($l->fresh()->last_status);
        Http::assertNothingSent();
    }

    // ---- pushProduct: semua listing yang memuat produk ini sbg komponen (bundle-aware) ----

    public function test_push_product_mengirim_listing_bundle_yang_memuat_produk_ini(): void
    {
        $this->fakeTiktokConfig();
        $this->tiktokConn();
        $a = $this->product('A-1');
        $b = $this->product('B-1');
        // BUNDLE-1 = 2x A + 1x B
        TiktokSkuMap::create(['tiktok_sku' => 'BUNDLE-1', 'product_id' => $a->id, 'qty' => 2]);
        TiktokSkuMap::create(['tiktok_sku' => 'BUNDLE-1', 'product_id' => $b->id, 'qty' => 1]);
        $this->svc()->setPool($a, 10); // floor(10/2) = 5
        $this->svc()->setPool($b, 20); // floor(20/1) = 20 -> min = 5
        MarketplaceListing::create([
            'channel' => 'tiktok', 'seller_sku' => 'BUNDLE-1', 'item_id' => 'PIDB',
            'variation_id' => 'SIDB', 'warehouse_id' => 'WH1', 'resolved_at' => now(),
        ]);
        // listing lain, TAK memuat produk A sbg komponen
        $c = $this->product('C-1');
        TiktokSkuMap::create(['tiktok_sku' => 'C-1', 'product_id' => $c->id, 'qty' => 1]);
        $this->svc()->setPool($c, 5);
        MarketplaceListing::create([
            'channel' => 'tiktok', 'seller_sku' => 'C-1', 'item_id' => 'PIDC',
            'variation_id' => 'SIDC', 'warehouse_id' => 'WH1', 'resolved_at' => now(),
        ]);
        Http::fake(['*/inventory/update*' => Http::response(['code' => 0, 'data' => []])]);

        $r = $this->svc()->pushProduct($a);

        $this->assertSame(['pushed' => 1, 'skipped' => 0, 'failed' => 0], $r);
        Http::assertSentCount(1);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/product/202309/products/PIDB/inventory/update'));
        $this->assertSame(5, MarketplaceListing::where('seller_sku', 'BUNDLE-1')->first()->last_pushed_qty);
        $this->assertNull(MarketplaceListing::where('seller_sku', 'C-1')->first()->last_pushed_qty);
    }

    public function test_push_product_default_force_true_kirim_ulang_walau_tak_berubah(): void
    {
        $this->fakeTiktokConfig();
        $this->tiktokConn();
        $p = $this->product('FM-1');
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        $this->svc()->setPool($p, 40);
        MarketplaceListing::create([
            'channel' => 'tiktok', 'seller_sku' => 'FM-1', 'item_id' => 'PID1',
            'variation_id' => 'SID1', 'warehouse_id' => 'WH1', 'last_pushed_qty' => 40,
            'last_status' => 'ok', 'resolved_at' => now(),
        ]);
        Http::fake(['*/inventory/update*' => Http::response(['code' => 0, 'data' => []])]);

        $r = $this->svc()->pushProduct($p);

        $this->assertSame(['pushed' => 1, 'skipped' => 0, 'failed' => 0], $r);
        Http::assertSentCount(1);
    }

    // ---- Shopee: pastikan cabang channel lain juga benar (tipe int, bukan string) ----

    public function test_push_listing_shopee_mengirim_item_id_dan_model_id_sebagai_integer(): void
    {
        $this->fakeShopeeConfig();
        $this->shopeeConn();
        $p = $this->product('SP-1');
        ShopeeSkuMap::create(['shopee_sku' => 'SP-1', 'product_id' => $p->id, 'qty' => 1]);
        $this->svc()->setPool($p, 15);
        $l = MarketplaceListing::create([
            'channel' => 'shopee', 'seller_sku' => 'SP-1', 'item_id' => '700',
            'variation_id' => '77', 'resolved_at' => now(),
        ]);
        Http::fake(['*update_stock*' => Http::response(['error' => '', 'response' => []])]);

        $result = $this->svc()->pushListing($l);

        $this->assertSame('ok', $result);
        $this->assertSame(15, $l->fresh()->last_pushed_qty);
        Http::assertSent(function ($req) {
            $b = $req->data();

            return str_contains($req->url(), '/api/v2/product/update_stock')
                && $b['item_id'] === 700
                && $b['stock_list'][0]['model_id'] === 77
                && $b['stock_list'][0]['seller_stock'][0]['stock'] === 15;
        });
    }

    public function test_push_listing_shopee_gagal_mencatat_last_error(): void
    {
        $this->fakeShopeeConfig();
        $this->shopeeConn();
        $p = $this->product('SP-1');
        ShopeeSkuMap::create(['shopee_sku' => 'SP-1', 'product_id' => $p->id, 'qty' => 1]);
        $this->svc()->setPool($p, 15);
        $l = MarketplaceListing::create([
            'channel' => 'shopee', 'seller_sku' => 'SP-1', 'item_id' => '700',
            'variation_id' => '77', 'resolved_at' => now(),
        ]);
        Http::fake(['*update_stock*' => Http::response(['error' => 'item_not_found', 'message' => 'Item tidak ada'])]);

        $result = $this->svc()->pushListing($l, true);

        $this->assertSame('failed', $result);
        $this->assertStringContainsString('Item tidak ada', $l->fresh()->last_error);
    }
}
