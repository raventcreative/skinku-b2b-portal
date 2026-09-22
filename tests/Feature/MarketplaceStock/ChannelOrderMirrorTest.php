<?php

namespace Tests\Feature\MarketplaceStock;

use App\Models\MarketplaceChannelOverride;
use App\Models\MarketplaceStock;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\TiktokSkuMap;
use App\Services\MarketplaceStockService;
use App\Services\TikTokOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 3 (Fase 1.5): cermin order channel-aware. Order pada channel yang
 * PUNYA override menurunkan override itu (Master TETAP); order pada channel
 * yang MASIH ikut Master menurunkan Master — persis Fase 1. HQ (StockMovement)
 * tak pernah tersentuh oleh mirror best-effort ini di kedua kasus.
 */
class ChannelOrderMirrorTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $sku = 'FM-1'): Product
    {
        return Product::create([
            'name' => 'Face Mist', 'sku' => $sku, 'status' => 'active',
            'price_distributor' => 1, 'price_reseller' => 1,
        ]);
    }

    public function test_order_di_channel_override_turunin_override_bukan_master(): void
    {
        $p = $this->product();
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        $svc = app(MarketplaceStockService::class);
        $svc->setPool($p, 20); // master 20, seeded
        $svc->setChannelOverride($p, 'tiktok', 8); // override tiktok 8, seeded
        $hqBefore = StockMovement::count();

        app(TikTokOrderService::class)->store([[
            'id' => 'O1', 'status' => 'AWAITING_SHIPMENT', 'create_time' => now()->addMinute()->timestamp,
            'payment' => ['total_amount' => 1, 'currency' => 'IDR'],
            'line_items' => [['seller_sku' => 'FM-1', 'quantity' => 3, 'product_name' => 'FM']],
        ]]);

        $this->assertSame(5, MarketplaceChannelOverride::where('product_id', $p->id)->where('channel', 'tiktok')->value('quantity')); // 8-3
        $this->assertSame(20, MarketplaceStock::where('product_id', $p->id)->value('quantity')); // master TETAP
        $this->assertSame($hqBefore, StockMovement::count()); // HQ tak berubah
    }

    public function test_order_channel_ikut_master_turunin_master(): void
    {
        $p = $this->product();
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        $svc = app(MarketplaceStockService::class);
        $svc->setPool($p, 20); // master 20, seeded — TANPA setChannelOverride
        $hqBefore = StockMovement::count();

        app(TikTokOrderService::class)->store([[
            'id' => 'O2', 'status' => 'AWAITING_SHIPMENT', 'create_time' => now()->addMinute()->timestamp,
            'payment' => ['total_amount' => 1, 'currency' => 'IDR'],
            'line_items' => [['seller_sku' => 'FM-1', 'quantity' => 3, 'product_name' => 'FM']],
        ]]);

        $this->assertSame(17, MarketplaceStock::where('product_id', $p->id)->value('quantity')); // 20-3, master turun
        $this->assertSame(0, MarketplaceChannelOverride::where('product_id', $p->id)->count()); // tak ada override dibuat
        $this->assertSame($hqBefore, StockMovement::count()); // HQ tak berubah
    }
}
