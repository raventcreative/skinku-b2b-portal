<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\Product;
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
        $l = MarketplaceListing::where('channel', 'tiktok')->where('seller_sku', 'FM-1')->first();
        $this->assertSame($m->id, $l->master_id);
    }

    public function test_resolve_tanpa_koneksi_mengembalikan_nol(): void
    {
        $r = app(MarketplaceMasterService::class)->resolveListings('tiktok');
        $this->assertSame(0, $r['found']);
    }
}
