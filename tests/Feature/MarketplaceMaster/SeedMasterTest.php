<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceMaster;
use App\Models\TiktokConnection;
use App\Services\MarketplaceMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeedMasterTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_set_base_stock_semua_unit_termasuk_bundle(): void
    {
        TiktokConnection::create(['shop_id' => 's', 'shop_cipher' => 'c', 'access_token' => 't', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);
        Http::fake([
            // Dua PRODUK terpisah (nama beda) — sejak dedup pindah ke NAMA (Task 2), dua
            // seller_sku dgn title PRODUK yang SAMA bakal digabung jadi 1 master; di sini
            // sengaja dibedakan namanya spy tetap 2 master terpisah (unit vs bundle), fokus
            // tes ini murni: bundle TETAP ikut di-seed dari findOrCreateMaster, tak di-skip.
            '*/product/202309/products/search*' => Http::response(['code' => 0, 'data' => [
                'products' => [
                    [
                        'id' => 'PID1', 'title' => 'Satuan',
                        'skus' => [['id' => 'S1', 'seller_sku' => 'SATUAN', 'inventory' => [['warehouse_id' => 'WH1', 'quantity' => 10]]]],
                    ],
                    [
                        'id' => 'PID2', 'title' => 'Paket Bundle 3pcs',
                        'skus' => [['id' => 'S2', 'seller_sku' => 'BUNDLE-3', 'inventory' => [['warehouse_id' => 'WH1', 'quantity' => 4]]]], // bundle: tetap di-seed
                    ],
                ],
                'next_page_token' => '',
            ]]),
            '*/logistics/202309/warehouses*' => Http::response(['code' => 0, 'data' => ['warehouses' => [['id' => 'WH1', 'type' => 'SALES_WAREHOUSE']]]]),
            '*' => Http::response(['code' => 0, 'data' => []]), // update_stock/price dari pushAll
        ]);

        $r = app(MarketplaceMasterService::class)->seedFromTiktok();

        $this->assertSame(2, $r['seeded']); // bundle IKUT di-seed
        $this->assertSame(10, MarketplaceMaster::where('master_sku', 'SATUAN')->value('base_stock'));
        $this->assertSame(4, MarketplaceMaster::where('master_sku', 'BUNDLE-3')->value('base_stock'));
        $this->assertTrue((bool) MarketplaceMaster::where('master_sku', 'BUNDLE-3')->value('is_bundle'));
    }

    public function test_seed_lewati_sku_tanpa_inventory(): void
    {
        TiktokConnection::create(['shop_id' => 's', 'shop_cipher' => 'c', 'access_token' => 't', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);
        Http::fake([
            '*/product/202309/products/search*' => Http::response(['code' => 0, 'data' => [
                'products' => [['id' => 'PID1', 'title' => 'X', 'skus' => [['id' => 'S1', 'seller_sku' => 'NOINV']]]],
                'next_page_token' => '',
            ]]),
            '*/logistics/202309/warehouses*' => Http::response(['code' => 0, 'data' => ['warehouses' => []]]),
            '*' => Http::response(['code' => 0, 'data' => []]),
        ]);

        $r = app(MarketplaceMasterService::class)->seedFromTiktok();
        $this->assertSame(1, $r['skipped']);
        $this->assertNull(MarketplaceMaster::where('master_sku', 'NOINV')->value('base_stock'));
    }
}
