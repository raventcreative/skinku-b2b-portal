<?php

namespace Tests\Feature\MarketplaceStock;

use App\Models\MarketplaceStock;
use App\Models\Product;
use App\Models\ShopeeOrder;
use App\Models\ShopeeSkuMap;
use App\Models\StockMovement;
use App\Models\TiktokOrder;
use App\Models\TiktokSkuMap;
use App\Services\MarketplaceStockService;
use App\Services\ShopeeOrderService;
use App\Services\TikTokOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 9 — cermin "Stok Marketplace" saat order MASUK. Hook di
 * TikTokOrderService::store()/ShopeeOrderService::store() harus menggeser
 * pool bersama (marketplace_stocks) TANPA pernah menyentuh stok HQ
 * (adjustHqStock/StockMovement) — mirror ini best-effort & independen dari
 * deduct()/reverse().
 */
class OrderMirrorTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $sku = 'FM-1'): Product
    {
        return Product::create([
            'name' => 'Face Mist', 'sku' => $sku, 'status' => 'active',
            'price_distributor' => 1, 'price_reseller' => 1,
        ]);
    }

    // ---------------------------------------------------------------
    // TikTok
    // ---------------------------------------------------------------

    public function test_tiktok_new_order_decrements_pool_not_hq(): void
    {
        $p = $this->product();
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        app(MarketplaceStockService::class)->setPool($p, 10); // seeded_at = now()
        $hqBefore = StockMovement::count();

        app(TikTokOrderService::class)->store([[
            'id' => 'O1', 'status' => 'AWAITING_SHIPMENT', 'create_time' => now()->addMinute()->timestamp,
            'payment' => ['total_amount' => 1, 'currency' => 'IDR'],
            'line_items' => [['seller_sku' => 'FM-1', 'quantity' => 3, 'product_name' => 'Face Mist']],
        ]]);

        $this->assertSame(7, MarketplaceStock::where('product_id', $p->id)->value('quantity')); // 10-3
        $this->assertSame($hqBefore, StockMovement::count()); // HQ tak berubah
    }

    public function test_tiktok_resync_same_order_does_not_double_decrement(): void
    {
        $p = $this->product();
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        app(MarketplaceStockService::class)->setPool($p, 10);

        $order = [
            'id' => 'O1', 'status' => 'AWAITING_SHIPMENT', 'create_time' => now()->addMinute()->timestamp,
            'payment' => ['total_amount' => 1, 'currency' => 'IDR'],
            'line_items' => [['seller_sku' => 'FM-1', 'quantity' => 3, 'product_name' => 'Face Mist']],
        ];

        app(TikTokOrderService::class)->store([$order]);
        app(TikTokOrderService::class)->store([$order]); // re-sync, status tak berubah

        $this->assertSame(7, MarketplaceStock::where('product_id', $p->id)->value('quantity'));
    }

    public function test_tiktok_order_before_seeded_at_is_ignored(): void
    {
        $p = $this->product();
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        app(MarketplaceStockService::class)->setPool($p, 10); // seeded_at = now()

        app(TikTokOrderService::class)->store([[
            'id' => 'OLD1', 'status' => 'AWAITING_SHIPMENT', 'create_time' => now()->subDay()->timestamp,
            'payment' => ['total_amount' => 1, 'currency' => 'IDR'],
            'line_items' => [['seller_sku' => 'FM-1', 'quantity' => 3, 'product_name' => 'Face Mist']],
        ]]);

        $this->assertSame(10, MarketplaceStock::where('product_id', $p->id)->value('quantity'));
    }

    public function test_tiktok_transition_to_cancelled_restores_pool(): void
    {
        $p = $this->product();
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        app(MarketplaceStockService::class)->setPool($p, 10);

        app(TikTokOrderService::class)->store([[
            'id' => 'O1', 'status' => 'AWAITING_SHIPMENT', 'create_time' => now()->addMinute()->timestamp,
            'payment' => ['total_amount' => 1, 'currency' => 'IDR'],
            'line_items' => [['seller_sku' => 'FM-1', 'quantity' => 3, 'product_name' => 'Face Mist']],
        ]]);
        $this->assertSame(7, MarketplaceStock::where('product_id', $p->id)->value('quantity'));

        app(TikTokOrderService::class)->store([[
            'id' => 'O1', 'status' => 'CANCELLED', 'create_time' => now()->addMinute()->timestamp,
            'payment' => ['total_amount' => 1, 'currency' => 'IDR'],
            'line_items' => [['seller_sku' => 'FM-1', 'quantity' => 3, 'product_name' => 'Face Mist']],
        ]]);

        $this->assertSame(10, MarketplaceStock::where('product_id', $p->id)->value('quantity'));
        $this->assertSame('CANCELLED', TiktokOrder::where('tiktok_order_id', 'O1')->value('status'));
    }

    /**
     * Order BARU yang lahir SUDAH batal (belum pernah tersimpan, status CANCELLED
     * sejak ingest pertama) tidak boleh menggeser pool sama sekali — bukan
     * kurangi-lalu-kembalikan (sign harus 0, bukan -1 lalu +1). Pool diseed dulu
     * supaya decrement AKAN kelihatan kalau salah terjadi.
     */
    public function test_tiktok_born_cancelled_order_leaves_pool_unchanged(): void
    {
        $p = $this->product();
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        app(MarketplaceStockService::class)->setPool($p, 10); // seeded_at = now()

        app(TikTokOrderService::class)->store([[
            'id' => 'OCX1', 'status' => 'CANCELLED', 'create_time' => now()->addMinute()->timestamp,
            'payment' => ['total_amount' => 1, 'currency' => 'IDR'],
            'line_items' => [['seller_sku' => 'FM-1', 'quantity' => 3, 'product_name' => 'Face Mist']],
        ]]);

        $this->assertSame(10, MarketplaceStock::where('product_id', $p->id)->value('quantity')); // tak berubah
        $this->assertSame('CANCELLED', TiktokOrder::where('tiktok_order_id', 'OCX1')->value('status'));
    }

    // ---------------------------------------------------------------
    // Shopee
    // ---------------------------------------------------------------

    public function test_shopee_new_order_decrements_pool_not_hq(): void
    {
        $p = $this->product();
        ShopeeSkuMap::create(['shopee_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        app(MarketplaceStockService::class)->setPool($p, 10);
        $hqBefore = StockMovement::count();

        app(ShopeeOrderService::class)->store([[
            'order_sn' => 'S1', 'order_status' => 'READY_TO_SHIP', 'total_amount' => 30000,
            'currency' => 'IDR', 'create_time' => now()->addMinute()->timestamp,
            'item_list' => [
                ['model_sku' => 'FM-1', 'item_name' => 'Face Mist', 'model_quantity_purchased' => 3, 'model_discounted_price' => 10000],
            ],
        ]]);

        $this->assertSame(7, MarketplaceStock::where('product_id', $p->id)->value('quantity'));
        $this->assertSame($hqBefore, StockMovement::count());
    }

    public function test_shopee_resync_same_order_does_not_double_decrement(): void
    {
        $p = $this->product();
        ShopeeSkuMap::create(['shopee_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        app(MarketplaceStockService::class)->setPool($p, 10);

        $order = [
            'order_sn' => 'S1', 'order_status' => 'READY_TO_SHIP', 'total_amount' => 30000,
            'currency' => 'IDR', 'create_time' => now()->addMinute()->timestamp,
            'item_list' => [
                ['model_sku' => 'FM-1', 'item_name' => 'Face Mist', 'model_quantity_purchased' => 3, 'model_discounted_price' => 10000],
            ],
        ];

        app(ShopeeOrderService::class)->store([$order]);
        app(ShopeeOrderService::class)->store([$order]);

        $this->assertSame(7, MarketplaceStock::where('product_id', $p->id)->value('quantity'));
    }

    public function test_shopee_order_before_seeded_at_is_ignored(): void
    {
        $p = $this->product();
        ShopeeSkuMap::create(['shopee_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        app(MarketplaceStockService::class)->setPool($p, 10);

        app(ShopeeOrderService::class)->store([[
            'order_sn' => 'SOLD1', 'order_status' => 'READY_TO_SHIP', 'total_amount' => 30000,
            'currency' => 'IDR', 'create_time' => now()->subDay()->timestamp,
            'item_list' => [
                ['model_sku' => 'FM-1', 'item_name' => 'Face Mist', 'model_quantity_purchased' => 3, 'model_discounted_price' => 10000],
            ],
        ]]);

        $this->assertSame(10, MarketplaceStock::where('product_id', $p->id)->value('quantity'));
    }

    public function test_shopee_transition_to_cancelled_restores_pool(): void
    {
        $p = $this->product();
        ShopeeSkuMap::create(['shopee_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        app(MarketplaceStockService::class)->setPool($p, 10);

        $base = [
            'order_sn' => 'S1', 'total_amount' => 30000, 'currency' => 'IDR',
            'create_time' => now()->addMinute()->timestamp,
            'item_list' => [
                ['model_sku' => 'FM-1', 'item_name' => 'Face Mist', 'model_quantity_purchased' => 3, 'model_discounted_price' => 10000],
            ],
        ];

        app(ShopeeOrderService::class)->store([['order_status' => 'READY_TO_SHIP'] + $base]);
        $this->assertSame(7, MarketplaceStock::where('product_id', $p->id)->value('quantity'));

        app(ShopeeOrderService::class)->store([['order_status' => 'CANCELLED'] + $base]);

        $this->assertSame(10, MarketplaceStock::where('product_id', $p->id)->value('quantity'));
        $this->assertSame('CANCELLED', ShopeeOrder::where('order_sn', 'S1')->value('status'));
    }

    /**
     * Sama seperti test_tiktok_born_cancelled_order_leaves_pool_unchanged() tapi
     * utk Shopee: order baru yang lahir SUDAH CANCELLED tidak boleh menggeser
     * pool (sign harus 0, bukan kurangi-lalu-kembalikan). Pool diseed dulu
     * supaya decrement AKAN kelihatan kalau salah terjadi.
     */
    public function test_shopee_born_cancelled_order_leaves_pool_unchanged(): void
    {
        $p = $this->product();
        ShopeeSkuMap::create(['shopee_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        app(MarketplaceStockService::class)->setPool($p, 10); // seeded_at = now()

        app(ShopeeOrderService::class)->store([[
            'order_sn' => 'SCX1', 'order_status' => 'CANCELLED', 'total_amount' => 30000,
            'currency' => 'IDR', 'create_time' => now()->addMinute()->timestamp,
            'item_list' => [
                ['model_sku' => 'FM-1', 'item_name' => 'Face Mist', 'model_quantity_purchased' => 3, 'model_discounted_price' => 10000],
            ],
        ]]);

        $this->assertSame(10, MarketplaceStock::where('product_id', $p->id)->value('quantity')); // tak berubah
        $this->assertSame('CANCELLED', ShopeeOrder::where('order_sn', 'SCX1')->value('status'));
    }
}
