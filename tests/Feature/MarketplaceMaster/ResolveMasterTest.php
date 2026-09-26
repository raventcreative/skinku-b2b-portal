<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\Product;
use App\Models\ShopeeConnection;
use App\Models\TiktokConnection;
use App\Services\MarketplaceMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResolveMasterTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_tiktok_auto_buat_master_dan_set_master_id(): void
    {
        TiktokConnection::create(['shop_id' => 's', 'shop_cipher' => 'c', 'access_token' => 't', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);
        // Produk internal ber-SKU sama → master.product_id keisi (opsional).
        Product::create(['name' => 'Face Mist', 'sku' => 'FM-1', 'status' => 'active', 'price_distributor' => 1, 'price_reseller' => 1]);

        Http::fake([
            '*/product/202309/products/search*' => Http::response(['code' => 0, 'data' => [
                'products' => [[
                    'id' => 'PID1', 'title' => 'Face Mist 60ml',
                    'main_images' => [['urls' => ['https://cdn.tiktok/fm1.jpg'], 'thumb_urls' => ['https://cdn.tiktok/fm1-thumb.jpg']]],
                    'skus' => [['id' => 'SKU1', 'seller_sku' => 'FM-1', 'inventory' => [['warehouse_id' => 'WH1', 'quantity' => 10]]]],
                ]],
                'next_page_token' => '',
            ]]),
            '*/logistics/202309/warehouses*' => Http::response(['code' => 0, 'data' => ['warehouses' => [['id' => 'WH1', 'type' => 'SALES_WAREHOUSE']]]]),
        ]);

        $r = app(MarketplaceMasterService::class)->resolveListings('tiktok');

        $this->assertSame(1, $r['found']);
        $this->assertSame(1, $r['mastered']);
        $m = MarketplaceMaster::where('master_sku', 'FM-1')->first();
        $this->assertNotNull($m);
        $this->assertSame('Face Mist 60ml', $m->name);
        $this->assertNotNull($m->product_id); // produk ber-SKU sama ditemukan
        // Field asli TikTok Product API 202309 (Image object): 'urls' (BUKAN 'url_list' ala
        // Shopee) — sama dgn skema yg dipakai TikTokClient::getProduct/EcomChatService.
        $this->assertSame('https://cdn.tiktok/fm1.jpg', $m->image_url);
        $l = MarketplaceListing::where('channel', 'tiktok')->where('seller_sku', 'FM-1')->first();
        $this->assertSame($m->id, $l->master_id);
    }

    public function test_resolve_tiktok_fallback_thumb_urls_kalau_urls_kosong(): void
    {
        TiktokConnection::create(['shop_id' => 's', 'shop_cipher' => 'c', 'access_token' => 't', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);

        Http::fake([
            '*/product/202309/products/search*' => Http::response(['code' => 0, 'data' => [
                'products' => [[
                    'id' => 'PID1', 'title' => 'Toner',
                    'main_images' => [['urls' => [], 'thumb_urls' => ['https://cdn.tiktok/toner-thumb.jpg']]],
                    'skus' => [['id' => 'SKU1', 'seller_sku' => 'TN-1']],
                ]],
                'next_page_token' => '',
            ]]),
            '*/logistics/202309/warehouses*' => Http::response(['code' => 0, 'data' => ['warehouses' => []]]),
        ]);

        app(MarketplaceMasterService::class)->resolveListings('tiktok');

        $this->assertSame('https://cdn.tiktok/toner-thumb.jpg', MarketplaceMaster::where('master_sku', 'TN-1')->value('image_url'));
    }

    public function test_resolve_tiktok_tanpa_main_images_image_url_null(): void
    {
        TiktokConnection::create(['shop_id' => 's', 'shop_cipher' => 'c', 'access_token' => 't', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);

        Http::fake([
            '*/product/202309/products/search*' => Http::response(['code' => 0, 'data' => [
                'products' => [[
                    'id' => 'PID1', 'title' => 'Serum',
                    'skus' => [['id' => 'SKU1', 'seller_sku' => 'SR-1']],
                ]],
                'next_page_token' => '',
            ]]),
            '*/logistics/202309/warehouses*' => Http::response(['code' => 0, 'data' => ['warehouses' => []]]),
        ]);

        app(MarketplaceMasterService::class)->resolveListings('tiktok');

        $this->assertNull(MarketplaceMaster::where('master_sku', 'SR-1')->value('image_url'));
    }

    public function test_resolve_tanpa_koneksi_mengembalikan_nol(): void
    {
        $r = app(MarketplaceMasterService::class)->resolveListings('tiktok');
        $this->assertSame(0, $r['found']);
    }

    /**
     * Regresi bug orphan: listing yang seller_sku-nya BEDA dari master_sku
     * master tujuan (persis pola "gabung listing" di TautkanTest — SEBELUM
     * pernah punya master sendiri) ditautkan manual ke master lain, lalu
     * channel melaporkan seller_sku yang sama lagi di resolve berikutnya.
     * upsertListing() TIDAK boleh membuat master baru ber-master_sku =
     * seller_sku listing ini (findOrCreateMaster tak nemu master_sku itu krn
     * listingnya sudah "pindah rumah") — kalau dipanggil tanpa syarat, ini
     * bikin master orphan permanen (tak ada yang mem-prune master).
     */
    public function test_resolve_ulang_tidak_membuat_master_orphan_untuk_listing_yang_sudah_ditautkan_manual(): void
    {
        TiktokConnection::create(['shop_id' => 's', 'shop_cipher' => 'c', 'access_token' => 't', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);

        $master = MarketplaceMaster::create(['master_sku' => 'REI-3', 'name' => 'Reina 3']);
        $listing = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'REI-30G', 'item_id' => 'IT']);
        app(MarketplaceMasterService::class)->tautkanListing($listing, $master->id);
        $countSebelum = MarketplaceMaster::count();

        Http::fake([
            '*/product/202309/products/search*' => Http::response(['code' => 0, 'data' => [
                'products' => [[
                    'id' => 'PID1', 'title' => 'Reina 30gr',
                    'skus' => [['id' => 'SKU1', 'seller_sku' => 'REI-30G']],
                ]],
                'next_page_token' => '',
            ]]),
            '*/logistics/202309/warehouses*' => Http::response(['code' => 0, 'data' => ['warehouses' => [['id' => 'WH1', 'type' => 'SALES_WAREHOUSE']]]]),
        ]);

        app(MarketplaceMasterService::class)->resolveListings('tiktok');

        $this->assertSame($countSebelum, MarketplaceMaster::count()); // tak ada master orphan baru
        $this->assertSame($master->id, $listing->refresh()->master_id); // tautan manual dipertahankan
    }

    /**
     * Kalau tak ada gudang bertipe SALES_WAREHOUSE (mis. field 'type' absen —
     * bentuk respons riil bisa beda dari dugaan), warehouse_id HARUS jatuh
     * balik ke gudang pertama, BUKAN null utk semua listing (lebih buruk drpd
     * proven MarketplaceStockService::resolveTiktok() yg pakai warehouses.0.id).
     */
    public function test_resolve_tiktok_fallback_ke_gudang_pertama_kalau_tak_ada_sales_warehouse(): void
    {
        TiktokConnection::create(['shop_id' => 's', 'shop_cipher' => 'c', 'access_token' => 't', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);

        Http::fake([
            '*/product/202309/products/search*' => Http::response(['code' => 0, 'data' => [
                'products' => [[
                    'id' => 'PID1', 'title' => 'Face Mist 60ml',
                    'skus' => [['id' => 'SKU1', 'seller_sku' => 'FM-1']],
                ]],
                'next_page_token' => '',
            ]]),
            // Tak satu pun gudang bertipe SALES_WAREHOUSE (field 'type' absen).
            '*/logistics/202309/warehouses*' => Http::response(['code' => 0, 'data' => ['warehouses' => [['id' => 'WH-FIRST'], ['id' => 'WH-SECOND']]]]),
        ]);

        app(MarketplaceMasterService::class)->resolveListings('tiktok');

        $l = MarketplaceListing::where('channel', 'tiktok')->where('seller_sku', 'FM-1')->first();
        $this->assertSame('WH-FIRST', $l->warehouse_id);
    }

    public function test_resolve_shopee_auto_buat_master_dan_set_master_id(): void
    {
        config()->set('services.shopee.partner_id', 1);
        config()->set('services.shopee.partner_key', 'k');
        config()->set('services.shopee.api_base', 'https://partner.shopeemobile.com');
        ShopeeConnection::create(['shop_id' => '123', 'access_token' => 't', 'refresh_token' => 'r', 'access_expires_at' => now()->addHours(3)]);

        Http::fake([
            '*get_item_list*' => Http::response(['error' => '', 'response' => [
                'item' => [['item_id' => 700]],
                'has_next_page' => false,
            ]]),
            '*get_item_base_info*' => Http::response(['error' => '', 'response' => ['item_list' => [
                ['item_id' => 700, 'item_name' => 'Reina 3', 'item_sku' => 'REI-3', 'has_model' => false, 'image' => ['image_url_list' => ['https://cdn.shopee/rei3.jpg']]],
            ]]]),
            '*get_model_list*' => Http::response(['error' => '', 'response' => ['model' => []]]),
        ]);

        $r = app(MarketplaceMasterService::class)->resolveListings('shopee');

        $this->assertSame(1, $r['found']);
        $this->assertSame(1, $r['mastered']);
        $m = MarketplaceMaster::where('master_sku', 'REI-3')->first();
        $this->assertNotNull($m);
        $this->assertSame('Reina 3', $m->name);
        // Shopee get_item_base_info: image.image_url_list (andal, tak butuh fallback).
        $this->assertSame('https://cdn.shopee/rei3.jpg', $m->image_url);
        $l = MarketplaceListing::where('channel', 'shopee')->where('seller_sku', 'REI-3')->first();
        $this->assertSame('700', $l->item_id);
        $this->assertSame('0', $l->variation_id);
        $this->assertSame($m->id, $l->master_id);
    }

    public function test_resolve_shopee_tanpa_image_field_image_url_null(): void
    {
        config()->set('services.shopee.partner_id', 1);
        config()->set('services.shopee.partner_key', 'k');
        config()->set('services.shopee.api_base', 'https://partner.shopeemobile.com');
        ShopeeConnection::create(['shop_id' => '123', 'access_token' => 't', 'refresh_token' => 'r', 'access_expires_at' => now()->addHours(3)]);

        Http::fake([
            '*get_item_list*' => Http::response(['error' => '', 'response' => [
                'item' => [['item_id' => 701]],
                'has_next_page' => false,
            ]]),
            '*get_item_base_info*' => Http::response(['error' => '', 'response' => ['item_list' => [
                ['item_id' => 701, 'item_name' => 'Toner Shopee', 'item_sku' => 'TN-SP', 'has_model' => false],
            ]]]),
            '*get_model_list*' => Http::response(['error' => '', 'response' => ['model' => []]]),
        ]);

        app(MarketplaceMasterService::class)->resolveListings('shopee');

        $this->assertNull(MarketplaceMaster::where('master_sku', 'TN-SP')->value('image_url'));
    }
}
