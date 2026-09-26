<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\TiktokConnection;
use App\Services\MarketplaceMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RefreshNoAutoMasterTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_tiktok_isi_listing_tanpa_auto_buat_master(): void
    {
        TiktokConnection::create([
            'shop_id' => 'S1', 'shop_cipher' => 'CIPHER', 'shop_name' => 'Toko',
            'access_token' => 'tok', 'refresh_token' => 'ref',
            'access_expires_at' => now()->addDay(), 'refresh_expires_at' => now()->addDays(30),
        ]);
        Http::fake([
            '*/logistics/202309/warehouses*' => Http::response(['code' => 0, 'data' => ['warehouses' => [['id' => 'W1', 'type' => 'SALES_WAREHOUSE']]]]),
            '*/product/202309/products/search*' => Http::response(['code' => 0, 'data' => [
                'products' => [[
                    'id' => 'P1', 'title' => 'Hana Glow', 'skus' => [['id' => 'SK1', 'seller_sku' => 'FM-1']],
                ]],
                'next_page_token' => '',
            ]]),
        ]);

        $out = app(MarketplaceMasterService::class)->resolveListings('tiktok');

        $this->assertSame(1, $out['found']);
        $this->assertArrayNotHasKey('mastered', $out);
        $this->assertSame(1, MarketplaceListing::where('channel', 'tiktok')->where('seller_sku', 'FM-1')->count());
        $this->assertNull(MarketplaceListing::where('seller_sku', 'FM-1')->value('master_id')); // TIDAK auto-termaster
        $this->assertSame(0, MarketplaceMaster::count()); // TIDAK ada master dibuat
    }
}
