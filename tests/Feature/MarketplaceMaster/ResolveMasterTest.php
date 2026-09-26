<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\ShopeeConnection;
use App\Models\TiktokConnection;
use App\Services\MarketplaceMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResolveMasterTest extends TestCase
{
    use RefreshDatabase;

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
     * Sejak jalur auto-master dibuang, upsertListing() TAK LAGI menyentuh
     * master_id sama sekali — jadi tautan manual otomatis aman & tak ada
     * master orphan baru yang mungkin dibuat.
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

    public function test_resolve_shopee_upsert_listing_tanpa_auto_buat_master(): void
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
        $this->assertArrayNotHasKey('mastered', $r);
        $l = MarketplaceListing::where('channel', 'shopee')->where('seller_sku', 'REI-3')->first();
        $this->assertNotNull($l);
        $this->assertSame('700', $l->item_id);
        $this->assertSame('0', $l->variation_id);
        $this->assertNull($l->master_id); // TIDAK auto-termaster
        $this->assertSame(0, MarketplaceMaster::count()); // TIDAK ada master dibuat
    }
}
