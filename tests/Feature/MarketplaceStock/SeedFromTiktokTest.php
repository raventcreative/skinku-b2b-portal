<?php

namespace Tests\Feature\MarketplaceStock;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceStock;
use App\Models\Product;
use App\Models\TiktokConnection;
use App\Models\TiktokSkuMap;
use App\Services\MarketplaceStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Task 8: seedFromTiktok() — set pool "Stok Marketplace" awal per produk dari
 * stok TikTok SAAT INI, hanya untuk listing 1:1 (satu komponen, qty 1); bundle
 * dilewati. Lalu jalankan pushAll() supaya Shopee (dan TikTok) ikut menyamai.
 *
 * Koreksi vs draf awal (lihat task-8-brief.md):
 * 1) TikTokClient::request() SUDAH unwrap ke $json['data'] (dibuktikan di
 *    ResolveListingsTest/resolveTiktok()) -> data_get() di sini TANPA prefiks
 *    'data.' (pakai 'products'/'next_page_token', bukan 'data.products').
 * 2) Anti-push-0: seed berakhir dgn pushAll() yg mengirim pool ke marketplace
 *    LIVE. Kalau quantity tak terbaca dari respons (key 'inventory' absen),
 *    JANGAN setPool(0) -- itu akan menge-nol-kan listing yg sebenarnya masih
 *    ada stoknya. SKIP saja (skipped++, tanpa membuat baris pool).
 */
class SeedFromTiktokTest extends TestCase
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

    private function tiktokConn(): TiktokConnection
    {
        return TiktokConnection::create([
            'shop_id' => 's', 'shop_cipher' => 'c', 'access_token' => 't', 'refresh_token' => 'r',
            'access_expires_at' => now()->addDay(),
        ]);
    }

    // ---- (a) 1:1 -> pool ter-set + seeded_at + counter ----

    public function test_seed_set_pool_dari_stok_tiktok_untuk_listing_1_1(): void
    {
        $this->fakeTiktokConfig();
        $this->tiktokConn();
        $p = $this->product('FM-1');
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);

        Http::fake([
            '*/product/202309/products/search*' => Http::response(['code' => 0, 'data' => ['products' => [
                ['id' => 'PID1', 'title' => 'Face Mist', 'skus' => [
                    ['id' => 'SID1', 'seller_sku' => 'FM-1', 'inventory' => [['quantity' => 33]]],
                ]],
            ]]]),
            '*' => Http::response(['code' => 0, 'data' => []]),
        ]);

        $res = $this->svc()->seedFromTiktok();

        $this->assertSame(['seeded' => 1, 'skipped' => 0], $res);
        $row = MarketplaceStock::where('product_id', $p->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(33, $row->quantity);
        $this->assertNotNull($row->seeded_at);
    }

    // ---- (b) sku TANPA key inventory -> skip, TIDAK bikin baris pool (anti-0) ----

    public function test_seed_melewati_sku_tanpa_inventory_dan_tidak_membuat_baris_pool(): void
    {
        $this->fakeTiktokConfig();
        $this->tiktokConn();
        $p = $this->product('FM-1');
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);

        Http::fake([
            '*/product/202309/products/search*' => Http::response(['code' => 0, 'data' => ['products' => [
                ['id' => 'PID1', 'title' => 'Face Mist', 'skus' => [
                    ['id' => 'SID1', 'seller_sku' => 'FM-1'], // TANPA 'inventory' sama sekali
                ]],
            ]]]),
            '*' => Http::response(['code' => 0, 'data' => []]),
        ]);

        $res = $this->svc()->seedFromTiktok();

        $this->assertSame(['seeded' => 0, 'skipped' => 1], $res);
        $this->assertSame(0, MarketplaceStock::where('product_id', $p->id)->count());
    }

    // ---- quantity=0 yg BENERAN ada di respons (bukan absen) -> TETAP di-seed sbg 0 ----
    // (beda dgn tes (b) di atas: 0 itu sah = stok TikTok memang habis, bukan "data tak
    // terbaca". Kalau kedua kasus ini disamakan pakai default 0 di data_get(), anti-0
    // safety-nya jadi tumpul: produk yg datanya SEBENARNYA absen akan ikut ke-set 0 juga.)

    public function test_seed_tetap_menyimpan_quantity_nol_yang_benar_benar_ada_di_respons(): void
    {
        $this->fakeTiktokConfig();
        $this->tiktokConn();
        $p = $this->product('FM-1');
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);

        Http::fake([
            '*/product/202309/products/search*' => Http::response(['code' => 0, 'data' => ['products' => [
                ['id' => 'PID1', 'title' => 'Face Mist', 'skus' => [
                    ['id' => 'SID1', 'seller_sku' => 'FM-1', 'inventory' => [['quantity' => 0]]],
                ]],
            ]]]),
            '*' => Http::response(['code' => 0, 'data' => []]),
        ]);

        $res = $this->svc()->seedFromTiktok();

        $this->assertSame(['seeded' => 1, 'skipped' => 0], $res);
        $row = MarketplaceStock::where('product_id', $p->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(0, $row->quantity);
        $this->assertNotNull($row->seeded_at);
    }

    // ---- (c) bundle (2 komponen) -> skip, tak ada pool dibuat utk komponen manapun ----

    public function test_seed_melewati_bundle_dua_komponen(): void
    {
        $this->fakeTiktokConfig();
        $this->tiktokConn();
        $a = $this->product('A-1');
        $b = $this->product('B-1');
        TiktokSkuMap::create(['tiktok_sku' => 'BUNDLE-1', 'product_id' => $a->id, 'qty' => 2]);
        TiktokSkuMap::create(['tiktok_sku' => 'BUNDLE-1', 'product_id' => $b->id, 'qty' => 1]);

        Http::fake([
            '*/product/202309/products/search*' => Http::response(['code' => 0, 'data' => ['products' => [
                ['id' => 'PIDB', 'title' => 'Bundle', 'skus' => [
                    ['id' => 'SIDB', 'seller_sku' => 'BUNDLE-1', 'inventory' => [['quantity' => 99]]],
                ]],
            ]]]),
            '*' => Http::response(['code' => 0, 'data' => []]),
        ]);

        $res = $this->svc()->seedFromTiktok();

        $this->assertSame(['seeded' => 0, 'skipped' => 1], $res);
        $this->assertSame(0, MarketplaceStock::count());
    }

    // ---- SKU 1 komponen tapi qty != 1 (mis. "2x produk A") juga BUKAN 1:1 -> skip ----

    public function test_seed_melewati_pemetaan_satu_komponen_tapi_qty_bukan_1(): void
    {
        $this->fakeTiktokConfig();
        $this->tiktokConn();
        $p = $this->product('DUOPACK');
        TiktokSkuMap::create(['tiktok_sku' => 'FM-2X', 'product_id' => $p->id, 'qty' => 2]);

        Http::fake([
            '*/product/202309/products/search*' => Http::response(['code' => 0, 'data' => ['products' => [
                ['id' => 'PID1', 'title' => 'Duopack', 'skus' => [
                    ['id' => 'SID1', 'seller_sku' => 'FM-2X', 'inventory' => [['quantity' => 10]]],
                ]],
            ]]]),
            '*' => Http::response(['code' => 0, 'data' => []]),
        ]);

        $res = $this->svc()->seedFromTiktok();

        $this->assertSame(['seeded' => 0, 'skipped' => 1], $res);
        $this->assertSame(0, MarketplaceStock::where('product_id', $p->id)->count());
    }

    // ---- pemetaan 1:1 tapi produknya sudah di-soft-delete (mapping lama blm dibersihkan) -> skip, bukan error ----
    // (product_id FK di tiktok_sku_maps cascadeOnDelete, jadi satu2nya cara realistis
    // punya mapping "yatim" tanpa melanggar FK adalah lewat SoftDeletes Product, bukan hard delete.)

    public function test_seed_melewati_ketika_produk_pada_pemetaan_tak_ditemukan(): void
    {
        $this->fakeTiktokConfig();
        $this->tiktokConn();
        $stale = $this->product('OLD-1');
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $stale->id, 'qty' => 1]);
        $stale->delete(); // soft delete: baris tetap ada (FK aman), Product::find() -> null

        Http::fake([
            '*/product/202309/products/search*' => Http::response(['code' => 0, 'data' => ['products' => [
                ['id' => 'PID1', 'title' => 'Face Mist', 'skus' => [
                    ['id' => 'SID1', 'seller_sku' => 'FM-1', 'inventory' => [['quantity' => 33]]],
                ]],
            ]]]),
            '*' => Http::response(['code' => 0, 'data' => []]),
        ]);

        $res = $this->svc()->seedFromTiktok();

        $this->assertSame(['seeded' => 0, 'skipped' => 1], $res);
        $this->assertSame(0, MarketplaceStock::count());
    }

    // ---- paging: next_page_token diikuti sampai habis (idiom sama dgn resolveTiktok()) ----

    public function test_seed_mengikuti_paging_sampai_next_page_token_habis(): void
    {
        $this->fakeTiktokConfig();
        $this->tiktokConn();
        $p1 = $this->product('FM-1');
        $p2 = $this->product('FM-2');
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p1->id, 'qty' => 1]);
        TiktokSkuMap::create(['tiktok_sku' => 'FM-2', 'product_id' => $p2->id, 'qty' => 1]);

        $call = 0;
        Http::fake(function ($request) use (&$call) {
            if (str_contains($request->url(), '/product/202309/products/search')) {
                $call++;

                return $call === 1
                    ? Http::response(['code' => 0, 'data' => [
                        'products' => [['id' => 'PID1', 'skus' => [['id' => 'SID1', 'seller_sku' => 'FM-1', 'inventory' => [['quantity' => 5]]]]]],
                        'next_page_token' => 'PAGE2',
                    ]])
                    : Http::response(['code' => 0, 'data' => [
                        'products' => [['id' => 'PID2', 'skus' => [['id' => 'SID2', 'seller_sku' => 'FM-2', 'inventory' => [['quantity' => 7]]]]]],
                        'next_page_token' => '',
                    ]]);
            }

            return Http::response(['code' => 0, 'data' => []]);
        });

        $res = $this->svc()->seedFromTiktok();

        $this->assertSame(['seeded' => 2, 'skipped' => 0], $res);
        $this->assertSame(2, $call);
        $this->assertSame(5, MarketplaceStock::where('product_id', $p1->id)->value('quantity'));
        $this->assertSame(7, MarketplaceStock::where('product_id', $p2->id)->value('quantity'));
    }

    // ---- setelah seed, pushAll() jalan supaya listing yg sudah ter-resolve ikut disamakan ----

    public function test_seed_menjalankan_push_all_agar_listing_lain_ikut_disamakan(): void
    {
        $this->fakeTiktokConfig();
        $this->tiktokConn();
        $p = $this->product('FM-1');
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        MarketplaceListing::create([
            'channel' => 'tiktok', 'seller_sku' => 'FM-1', 'item_id' => 'PID1',
            'variation_id' => 'SID1', 'warehouse_id' => 'WH1', 'resolved_at' => now(),
        ]);

        Http::fake([
            '*/product/202309/products/search*' => Http::response(['code' => 0, 'data' => ['products' => [
                ['id' => 'PID1', 'title' => 'Face Mist', 'skus' => [
                    ['id' => 'SID1', 'seller_sku' => 'FM-1', 'inventory' => [['quantity' => 33]]],
                ]],
            ]]]),
            '*/inventory/update*' => Http::response(['code' => 0, 'data' => []]),
            '*' => Http::response(['code' => 0, 'data' => []]),
        ]);

        $this->svc()->seedFromTiktok();

        Http::assertSent(function ($req) {
            $b = $req->data();

            return str_contains($req->url(), '/product/202309/products/PID1/inventory/update')
                && $b['skus'][0]['inventory'][0]['quantity'] === 33;
        });
        $this->assertSame(33, MarketplaceListing::where('seller_sku', 'FM-1')->value('last_pushed_qty'));
    }

    // ---- belum ada koneksi TikTok -> nol, tak menyentuh apa pun ----

    public function test_seed_mengembalikan_nol_ketika_belum_ada_koneksi_tiktok(): void
    {
        Http::fake();

        $res = $this->svc()->seedFromTiktok();

        $this->assertSame(['seeded' => 0, 'skipped' => 0], $res);
        $this->assertSame(0, MarketplaceStock::count());
        Http::assertNothingSent();
    }
}
