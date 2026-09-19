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
 * Task 5: resolveListings() — isi item_id/variation_id/warehouse_id ke
 * marketplace_listings dari daftar produk channel, tandai 'unmapped' kalau
 * seller_sku tak ada di peta SKU (& bukan Product.sku). Belum push stok
 * (task lain) — jadi listing yang baru dipetakan TIDAK diberi last_status='ok'
 * di sini, cuma dibiarkan/dipertahankan.
 *
 * Catatan bentuk balasan (dicek via kode+tes Task 4, bukan tebakan):
 * - TikTokClient::request()/authCall() SUDAH unwrap ke $json['data'] (lihat
 *   ClientUpdateStockTest::test_tiktok_search_products_... & ...get_warehouses...
 *   yang mengakses $data['products']/$data['warehouses'] TANPA prefiks 'data.').
 *   Jadi di resolveTiktok(), data_get() memakai path TANPA 'data.' di depan.
 * - ShopeeClient::shopCall()/handle() TIDAK unwrap — balikin envelope penuh
 *   {error, response:{...}} (lihat ClientUpdateStockTest utk get_item_list/
 *   get_model_list, dan EcomChatService::enrichShopeeProduct utk
 *   get_item_base_info -> response.item_list). Jadi path Shopee tetap pakai
 *   prefiks 'response.'.
 */
class ResolveListingsTest extends TestCase
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

    public function test_resolve_tiktok_mengisi_item_id_variation_id_warehouse_dan_menandai_unmapped(): void
    {
        $this->fakeTiktokConfig();
        TiktokConnection::create([
            'shop_id' => 's', 'shop_cipher' => 'c', 'access_token' => 't', 'refresh_token' => 'r',
            'access_expires_at' => now()->addDay(),
        ]);
        $p = $this->product('FM-1');
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);

        Http::fake([
            '*/product/202309/products/search*' => Http::response(['code' => 0, 'data' => ['products' => [
                ['id' => 'PID1', 'title' => 'Face Mist', 'skus' => [['id' => 'SID1', 'seller_sku' => 'FM-1']]],
                ['id' => 'PID2', 'title' => 'Lain', 'skus' => [['id' => 'SID2', 'seller_sku' => 'ZZZ']]],
            ], 'total_count' => 2]]),
            '*/logistics/202309/warehouses*' => Http::response(['code' => 0, 'data' => ['warehouses' => [['id' => 'WH1']]]]),
        ]);

        $res = $this->svc()->resolveListings('tiktok');

        $this->assertSame(['found' => 2, 'mapped' => 1, 'unmapped' => 1], $res);

        $mapped = MarketplaceListing::where('channel', 'tiktok')->where('seller_sku', 'FM-1')->first();
        $this->assertSame('PID1', $mapped->item_id);
        $this->assertSame('SID1', $mapped->variation_id);
        $this->assertSame('WH1', $mapped->warehouse_id);
        $this->assertSame('Face Mist', $mapped->title);
        $this->assertNull($mapped->last_status); // belum di-push -> bukan tugas task ini menyetel 'ok'
        $this->assertNotNull($mapped->resolved_at);

        $unmapped = MarketplaceListing::where('channel', 'tiktok')->where('seller_sku', 'ZZZ')->first();
        $this->assertSame('unmapped', $unmapped->last_status);
        $this->assertSame('PID2', $unmapped->item_id);
        $this->assertSame('SID2', $unmapped->variation_id);
    }

    public function test_resolve_tiktok_tidak_menimpa_status_ok_yang_sudah_ada_dan_tidak_menyentuh_kolom_lain(): void
    {
        $this->fakeTiktokConfig();
        TiktokConnection::create([
            'shop_id' => 's', 'shop_cipher' => 'c', 'access_token' => 't', 'refresh_token' => 'r',
            'access_expires_at' => now()->addDay(),
        ]);
        $p = $this->product('FM-1');
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        MarketplaceListing::create([
            'channel' => 'tiktok', 'seller_sku' => 'FM-1', 'item_id' => 'OLD', 'variation_id' => 'OLD',
            'last_status' => 'ok', 'last_pushed_qty' => 10,
        ]);

        Http::fake([
            '*/product/202309/products/search*' => Http::response(['code' => 0, 'data' => ['products' => [
                ['id' => 'PID1', 'title' => 'Face Mist', 'skus' => [['id' => 'SID1', 'seller_sku' => 'FM-1']]],
            ]]]),
            '*/logistics/202309/warehouses*' => Http::response(['code' => 0, 'data' => ['warehouses' => []]]),
        ]);

        $this->svc()->resolveListings('tiktok');

        $row = MarketplaceListing::where('channel', 'tiktok')->where('seller_sku', 'FM-1')->first();
        $this->assertSame('ok', $row->last_status); // dipertahankan, bukan ditimpa null
        $this->assertSame('PID1', $row->item_id); // tapi ID channel tetap disegarkan
        $this->assertSame('SID1', $row->variation_id);
        $this->assertSame(10, $row->last_pushed_qty); // kolom di luar resolve tak disentuh
    }

    public function test_resolve_shopee_mengisi_item_id_model_id_untuk_item_bervarian_dan_tanpa_varian(): void
    {
        $this->fakeShopeeConfig();
        ShopeeConnection::create([
            'shop_id' => '123', 'access_token' => 't', 'refresh_token' => 'r',
            'access_expires_at' => now()->addHours(3),
        ]);
        $p1 = $this->product('SP-1');
        ShopeeSkuMap::create(['shopee_sku' => 'SP-1', 'product_id' => $p1->id, 'qty' => 1]);
        $p2 = $this->product('SP-2');
        ShopeeSkuMap::create(['shopee_sku' => 'SP-2', 'product_id' => $p2->id, 'qty' => 1]);

        Http::fake([
            '*get_item_list*' => Http::response(['error' => '', 'response' => [
                'item' => [['item_id' => 700], ['item_id' => 701]],
                'has_next_page' => false,
            ]]),
            '*get_item_base_info*' => Http::response(['error' => '', 'response' => ['item_list' => [
                ['item_id' => 700, 'item_name' => 'Varian', 'item_sku' => '', 'has_model' => true],
                ['item_id' => 701, 'item_name' => 'Polos', 'item_sku' => 'SP-2', 'has_model' => false],
            ]]]),
            '*get_model_list*' => Http::response(['error' => '', 'response' => ['model' => [
                ['model_id' => 77, 'model_sku' => 'SP-1'],
                ['model_id' => 78, 'model_sku' => 'YYY'],
            ]]]),
        ]);

        $res = $this->svc()->resolveListings('shopee');

        $this->assertSame(['found' => 3, 'mapped' => 2, 'unmapped' => 1], $res);

        $variant = MarketplaceListing::where('channel', 'shopee')->where('seller_sku', 'SP-1')->first();
        $this->assertSame('700', $variant->item_id);
        $this->assertSame('77', $variant->variation_id);
        $this->assertNull($variant->warehouse_id);
        $this->assertSame('Varian', $variant->title);

        $unmapped = MarketplaceListing::where('channel', 'shopee')->where('seller_sku', 'YYY')->first();
        $this->assertSame('unmapped', $unmapped->last_status);
        $this->assertSame('700', $unmapped->item_id);
        $this->assertSame('78', $unmapped->variation_id);

        $plain = MarketplaceListing::where('channel', 'shopee')->where('seller_sku', 'SP-2')->first();
        $this->assertSame('701', $plain->item_id);
        $this->assertSame('0', $plain->variation_id);
        $this->assertNull($plain->last_status);
    }

    public function test_resolve_listings_mengembalikan_nol_ketika_belum_ada_koneksi(): void
    {
        $this->assertSame(['found' => 0, 'mapped' => 0, 'unmapped' => 0], $this->svc()->resolveListings('tiktok'));
        $this->assertSame(['found' => 0, 'mapped' => 0, 'unmapped' => 0], $this->svc()->resolveListings('shopee'));
        $this->assertSame(0, MarketplaceListing::count());
    }
}
