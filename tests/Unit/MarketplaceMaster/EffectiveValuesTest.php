<?php

namespace Tests\Unit\MarketplaceMaster;

use App\Models\MarketplaceMaster;
use App\Models\MarketplaceMasterChannel;
use App\Services\MarketplaceMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EffectiveValuesTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): MarketplaceMasterService
    {
        return app(MarketplaceMasterService::class);
    }

    public function test_stok_efektif_override_menang_lalu_base_lalu_null(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A']);
        $this->assertNull($this->svc()->effectiveStock($m, 'tiktok')); // belum ada apa-apa

        $m->update(['base_stock' => 40]);
        $this->assertSame(40, $this->svc()->effectiveStock($m->refresh(), 'tiktok')); // base

        MarketplaceMasterChannel::create(['master_id' => $m->id, 'channel' => 'tiktok', 'stock' => 12]);
        $this->assertSame(12, $this->svc()->effectiveStock($m->refresh(), 'tiktok')); // override menang
        $this->assertSame(40, $this->svc()->effectiveStock($m, 'shopee')); // channel lain tetap base
    }

    public function test_harga_efektif_override_menang_lalu_base_lalu_null(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A']);
        $this->assertNull($this->svc()->effectivePrice($m, 'tiktok'));

        $m->update(['base_price' => 39000]);
        $this->assertSame(39000.0, $this->svc()->effectivePrice($m->refresh(), 'tiktok'));

        MarketplaceMasterChannel::create(['master_id' => $m->id, 'channel' => 'tiktok', 'price' => 42000]);
        $this->assertSame(42000.0, $this->svc()->effectivePrice($m->refresh(), 'tiktok'));
    }

    public function test_override_stok_saja_harga_ikut_base(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'base_stock' => 40, 'base_price' => 39000]);
        MarketplaceMasterChannel::create(['master_id' => $m->id, 'channel' => 'tiktok', 'stock' => 5, 'price' => null]);
        $this->assertSame(5, $this->svc()->effectiveStock($m, 'tiktok'));
        $this->assertSame(39000.0, $this->svc()->effectivePrice($m, 'tiktok')); // price override null → ikut base
    }
}
