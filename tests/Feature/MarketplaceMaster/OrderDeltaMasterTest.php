<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\MarketplaceMasterChannel;
use App\Services\MarketplaceMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderDeltaMasterTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_kurangi_base_stock_master(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'base_stock' => 20, 'seeded_at' => now()->subDay()]);
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'A', 'master_id' => $m->id]);

        app(MarketplaceMasterService::class)->applyOrderDelta($l, -3, now());
        $this->assertSame(17, $m->refresh()->base_stock);
    }

    public function test_order_kurangi_override_channel_kalau_ada(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'base_stock' => 20, 'seeded_at' => now()->subDay()]);
        MarketplaceMasterChannel::create(['master_id' => $m->id, 'channel' => 'tiktok', 'stock' => 8, 'seeded_at' => now()->subDay()]);
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'A', 'master_id' => $m->id]);

        app(MarketplaceMasterService::class)->applyOrderDelta($l, -3, now());
        $this->assertSame(5, MarketplaceMasterChannel::where('master_id', $m->id)->where('channel', 'tiktok')->value('stock'));
        $this->assertSame(20, $m->refresh()->base_stock); // base tak tersentuh
    }

    public function test_order_sebelum_seeded_diabaikan(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'base_stock' => 20, 'seeded_at' => now()]);
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'A', 'master_id' => $m->id]);
        app(MarketplaceMasterService::class)->applyOrderDelta($l, -3, now()->subDay()); // sebelum seed
        $this->assertSame(20, $m->refresh()->base_stock);
    }

    public function test_clamp_tidak_minus(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'base_stock' => 2, 'seeded_at' => now()->subDay()]);
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'A', 'master_id' => $m->id]);
        app(MarketplaceMasterService::class)->applyOrderDelta($l, -5, now());
        $this->assertSame(0, $m->refresh()->base_stock);
    }

    public function test_tanpa_master_atau_belum_seed_no_op(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'base_stock' => 20, 'seeded_at' => null]); // belum seed
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'A', 'master_id' => $m->id]);
        app(MarketplaceMasterService::class)->applyOrderDelta($l, -3, now());
        $this->assertSame(20, $m->refresh()->base_stock);

        $l2 = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'B', 'master_id' => null]);
        app(MarketplaceMasterService::class)->applyOrderDelta($l2, -3, now()); // tak ada master → no-op, tak error
        $this->assertTrue(true);
    }
}
