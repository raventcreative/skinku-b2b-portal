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

class PushMasterTest extends TestCase
{
    use RefreshDatabase;

    private function tiktokConn(): void
    {
        TiktokConnection::create(['shop_id' => 's', 'shop_cipher' => 'c', 'access_token' => 't', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);
    }

    public function test_push_listing_kirim_stok_dan_harga_lalu_catat(): void
    {
        $this->tiktokConn();
        Http::fake(['*' => Http::response(['code' => 0, 'data' => []])]);
        $m = MarketplaceMaster::create(['master_sku' => 'FM-1', 'name' => 'FM', 'base_stock' => 30, 'base_price' => 39000]);
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'FM-1', 'master_id' => $m->id, 'item_id' => 'PID1', 'variation_id' => 'SKU1', 'warehouse_id' => 'WH1']);

        $r = app(MarketplaceMasterService::class)->pushListing($l, true);

        $this->assertSame('ok', $r['stock']);
        $this->assertSame('ok', $r['price']);
        $l->refresh();
        $this->assertSame(30, $l->last_pushed_qty);
        $this->assertSame('39000.00', (string) $l->last_pushed_price);
        $this->assertSame('ok', $l->last_status);
        $this->assertSame('ok', $l->last_price_status);
        Http::assertSentCount(2); // 1 update_stock + 1 update_price
    }

    public function test_push_listing_null_dilewati_tanpa_http(): void
    {
        $this->tiktokConn();
        Http::fake();
        $m = MarketplaceMaster::create(['master_sku' => 'FM-1', 'name' => 'FM']); // base_stock & base_price null
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'FM-1', 'master_id' => $m->id, 'item_id' => 'PID1', 'variation_id' => 'SKU1', 'warehouse_id' => 'WH1']);

        $r = app(MarketplaceMasterService::class)->pushListing($l, true);

        $this->assertSame('skip', $r['stock']);
        $this->assertSame('skip', $r['price']);
        Http::assertNothingSent();
    }

    public function test_push_listing_tanpa_master_dilewati(): void
    {
        $this->tiktokConn();
        Http::fake();
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'X', 'master_id' => null, 'item_id' => 'PID1']);
        $r = app(MarketplaceMasterService::class)->pushListing($l, true);
        $this->assertSame('skip', $r['stock']);
        Http::assertNothingSent();
    }

    public function test_push_diff_hanya_kirim_yang_berubah(): void
    {
        $this->tiktokConn();
        Http::fake(['*' => Http::response(['code' => 0, 'data' => []])]);
        $m = MarketplaceMaster::create(['master_sku' => 'FM-1', 'name' => 'FM', 'base_stock' => 30, 'base_price' => 39000]);
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'FM-1', 'master_id' => $m->id, 'item_id' => 'PID1', 'variation_id' => 'SKU1', 'warehouse_id' => 'WH1',
            'last_pushed_qty' => 30, 'last_pushed_price' => 39000]);

        $r = app(MarketplaceMasterService::class)->pushListing($l, false); // tak berubah → skip dua-duanya
        $this->assertSame('skip', $r['stock']);
        $this->assertSame('skip', $r['price']);
        Http::assertNothingSent();
    }

    public function test_push_listing_gagal_catat_status_dan_error(): void
    {
        $this->tiktokConn();
        Http::fake(['*' => Http::response(['code' => 36004, 'message' => 'no permission'], 200)]);
        $m = MarketplaceMaster::create(['master_sku' => 'FM-1', 'name' => 'FM', 'base_stock' => 30]);
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'FM-1', 'master_id' => $m->id, 'item_id' => 'PID1', 'variation_id' => 'SKU1', 'warehouse_id' => 'WH1']);

        $r = app(MarketplaceMasterService::class)->pushListing($l, true);
        $this->assertSame('failed', $r['stock']);
        $this->assertSame('failed', $l->refresh()->last_status);
        $this->assertNotNull($l->last_error);
    }

    public function test_push_shopee_kirim_item_dan_model_id_integer(): void
    {
        ShopeeConnection::create(['shop_id' => '123', 'access_token' => 't', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);
        Http::fake(['*' => Http::response(['error' => '', 'response' => []])]);
        $m = MarketplaceMaster::create(['master_sku' => 'S-1', 'name' => 'S', 'base_stock' => 8, 'base_price' => 12000]);
        $l = MarketplaceListing::create(['channel' => 'shopee', 'seller_sku' => 'S-1', 'master_id' => $m->id, 'item_id' => '555', 'variation_id' => '66']);

        $r = app(MarketplaceMasterService::class)->pushListing($l, true);
        $this->assertSame('ok', $r['stock']);
        $this->assertSame('ok', $r['price']);
    }
}
