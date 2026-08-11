<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ShopeeOrder;
use App\Models\StockMovement;
use App\Models\TiktokOrder;
use App\Services\HqStockReportService;
use App\Services\ShopeeOrderService;
use App\Services\TikTokOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MarketplaceBackdateMovementTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function product(string $sku, int $stock = 100): Product
    {
        return Product::create([
            'name' => 'Produk '.(++$this->seq), 'sku' => $sku, 'hq_stock' => $stock,
            'status' => 'active', 'price_distributor' => 1, 'price_reseller' => 1,
        ]);
    }

    public function test_tiktok_deduct_movement_dated_by_order_date(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');
        $p = $this->product('SKU-A');
        $order = TiktokOrder::create([
            'tiktok_order_id' => 'TT1', 'status' => 'COMPLETED',
            'stock_status' => TiktokOrder::STATUS_PENDING, 'order_created_at' => '2026-08-05 09:00:00',
            'line_items' => [['sku' => 'SKU-A', 'name' => 'Sabun', 'qty' => 3]],
        ]);

        app(TikTokOrderService::class)->deduct($order, null);

        $mv = StockMovement::where('reference_type', 'tiktok_order')->where('reference_id', $order->id)->first();
        $this->assertNotNull($mv);
        $this->assertSame('2026-08-05', $mv->created_at->toDateString());   // bukan 08-11
        $this->assertSame(97, (int) $p->fresh()->hq_stock);                 // saldo tetap benar
    }

    public function test_tiktok_reverse_leg_also_dated_by_order_date(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');
        $p = $this->product('SKU-A');
        $order = TiktokOrder::create([
            'tiktok_order_id' => 'TT2', 'status' => 'COMPLETED',
            'stock_status' => TiktokOrder::STATUS_PENDING, 'order_created_at' => '2026-08-05 09:00:00',
            'line_items' => [['sku' => 'SKU-A', 'name' => 'Sabun', 'qty' => 3]],
        ]);
        $svc = app(TikTokOrderService::class);

        $svc->deduct($order, null);
        $svc->reverse($order->fresh());

        $mvs = StockMovement::where('reference_type', 'tiktok_order')->where('reference_id', $order->id)->get();
        $this->assertCount(2, $mvs);                            // OUT + IN
        foreach ($mvs as $mv) {
            $this->assertSame('2026-08-05', $mv->created_at->toDateString());  // keduanya di tgl order
        }
        $this->assertSame(100, (int) $p->fresh()->hq_stock);   // net-nol
    }

    public function test_tiktok_sale_lands_on_order_day_in_report_not_deduct_day(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');
        $p = $this->product('SKU-A');
        $order = TiktokOrder::create([
            'tiktok_order_id' => 'TT3', 'status' => 'COMPLETED',
            'stock_status' => TiktokOrder::STATUS_PENDING, 'order_created_at' => '2026-08-05 09:00:00',
            'line_items' => [['sku' => 'SKU-A', 'name' => 'Sabun', 'qty' => 3]],
        ]);
        app(TikTokOrderService::class)->deduct($order, null);

        $svc = app(HqStockReportService::class);
        $orderDay = collect($svc->report('harian', Carbon::parse('2026-08-05'))['rows'])->firstWhere('product.id', $p->id);
        $deductDay = collect($svc->report('harian', Carbon::parse('2026-08-11'))['rows'])->firstWhere('product.id', $p->id);

        $this->assertSame(3, $orderDay['tiktok']);      // penjualan muncul 5 Agu
        $this->assertSame(100, $orderDay['awal']);
        $this->assertSame(97, $orderDay['akhir']);
        $this->assertSame(0, $deductDay['tiktok']);     // TIDAK muncul 11 Agu (hari potong)
    }

    public function test_tiktok_deduct_falls_back_to_now_when_order_date_missing(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');
        $p = $this->product('SKU-A');
        $order = TiktokOrder::create([
            'tiktok_order_id' => 'TTNULL', 'status' => 'COMPLETED',
            'stock_status' => TiktokOrder::STATUS_PENDING, 'order_created_at' => null,
            'line_items' => [['sku' => 'SKU-A', 'name' => 'x', 'qty' => 1]],
        ]);

        app(TikTokOrderService::class)->deduct($order, null);

        $mv = StockMovement::where('reference_type', 'tiktok_order')->where('reference_id', $order->id)->first();
        $this->assertSame('2026-08-11', $mv->created_at->toDateString());   // fallback now()
    }

    public function test_shopee_deduct_movement_dated_by_order_date(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');
        $p = $this->product('SKU-B');
        $order = ShopeeOrder::create([
            'order_sn' => 'SP1', 'status' => 'COMPLETED',
            'stock_status' => ShopeeOrder::STATUS_PENDING, 'order_created_at' => '2026-08-05 09:00:00',
            'line_items' => [['sku' => 'SKU-B', 'name' => 'x', 'qty' => 4]],
        ]);

        app(ShopeeOrderService::class)->deduct($order, null);

        $mv = StockMovement::where('reference_type', 'shopee_order')->where('reference_id', $order->id)->first();
        $this->assertNotNull($mv);
        $this->assertSame('2026-08-05', $mv->created_at->toDateString());
        $this->assertSame(96, (int) $p->fresh()->hq_stock);
    }

    public function test_shopee_reverse_leg_also_dated_by_order_date(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');
        $p = $this->product('SKU-B');
        $order = ShopeeOrder::create([
            'order_sn' => 'SP2', 'status' => 'COMPLETED',
            'stock_status' => ShopeeOrder::STATUS_PENDING, 'order_created_at' => '2026-08-05 09:00:00',
            'line_items' => [['sku' => 'SKU-B', 'name' => 'x', 'qty' => 4]],
        ]);
        $svc = app(ShopeeOrderService::class);

        $svc->deduct($order, null);
        $svc->reverse($order->fresh());

        $mvs = StockMovement::where('reference_type', 'shopee_order')->where('reference_id', $order->id)->get();
        $this->assertCount(2, $mvs);
        foreach ($mvs as $mv) {
            $this->assertSame('2026-08-05', $mv->created_at->toDateString());
        }
        $this->assertSame(100, (int) $p->fresh()->hq_stock);
    }
}
