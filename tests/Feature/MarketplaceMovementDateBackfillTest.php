<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ShopeeOrder;
use App\Models\StockMovement;
use App\Models\TiktokConnection;
use App\Models\TiktokOrder;
use App\Support\MarketplaceMovementDateBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplaceMovementDateBackfillTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function product(string $sku): Product
    {
        return Product::create([
            'name' => 'Produk '.(++$this->seq), 'sku' => $sku, 'hq_stock' => 100,
            'status' => 'active', 'price_distributor' => 1, 'price_reseller' => 1,
        ]);
    }

    private function movement(string $ref, int $refId, int $pid, string $type, int $qty, int $before, int $after, string $createdAt): void
    {
        StockMovement::create([
            'product_id' => $pid, 'user_id' => null, 'movement_type' => $type, 'quantity' => $qty,
            'before_qty' => $before, 'after_qty' => $after, 'reference_type' => $ref, 'reference_id' => $refId,
            'created_at' => $createdAt,
        ]);
    }

    public function test_moves_tiktok_movement_to_order_date(): void
    {
        $p = $this->product('SKU-A');
        $order = TiktokOrder::create([
            'tiktok_order_id' => 'TT1', 'status' => 'COMPLETED', 'stock_status' => TiktokOrder::STATUS_DEDUCTED,
            'order_created_at' => '2026-08-05 09:00:00',
            'line_items' => [['sku' => 'SKU-A', 'name' => 'x', 'qty' => 3]],
        ]);
        // gerakan lama dicap hari potong (SALAH)
        $this->movement('tiktok_order', $order->id, $p->id, 'OUT', 3, 100, 97, '2026-08-11 10:00:00');

        MarketplaceMovementDateBackfill::run();

        $mv = StockMovement::where('reference_type', 'tiktok_order')->where('reference_id', $order->id)->first();
        $this->assertSame('2026-08-05', $mv->created_at->toDateString());
    }

    public function test_moves_both_legs_to_order_date(): void
    {
        $p = $this->product('SKU-B');
        $order = ShopeeOrder::create([
            'order_sn' => 'SP1', 'status' => 'COMPLETED', 'stock_status' => ShopeeOrder::STATUS_PENDING,
            'order_created_at' => '2026-08-05 09:00:00',
            'line_items' => [['sku' => 'SKU-B', 'name' => 'x', 'qty' => 4]],
        ]);
        // potong (OUT) 11 Agu + batal (IN) 12 Agu — keduanya salah tanggal
        $this->movement('shopee_order', $order->id, $p->id, 'OUT', 4, 100, 96, '2026-08-11 10:00:00');
        $this->movement('shopee_order', $order->id, $p->id, 'IN', 4, 96, 100, '2026-08-12 10:00:00');

        MarketplaceMovementDateBackfill::run();

        $mvs = StockMovement::where('reference_type', 'shopee_order')->where('reference_id', $order->id)->get();
        $this->assertCount(2, $mvs);
        foreach ($mvs as $mv) {
            $this->assertSame('2026-08-05', $mv->created_at->toDateString());
        }
    }

    public function test_clamps_to_deduct_from(): void
    {
        TiktokConnection::create([
            'shop_id' => 'S', 'shop_cipher' => 'C', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addDay(), 'deduct_from' => '2026-07-15',
        ]);
        $p = $this->product('SKU-A');
        $order = TiktokOrder::create([
            'tiktok_order_id' => 'TTOLD', 'status' => 'COMPLETED', 'stock_status' => TiktokOrder::STATUS_DEDUCTED,
            'order_created_at' => '2026-07-10 09:00:00',   // SEBELUM titik-nol
            'line_items' => [['sku' => 'SKU-A', 'name' => 'x', 'qty' => 1]],
        ]);
        $this->movement('tiktok_order', $order->id, $p->id, 'OUT', 1, 100, 99, '2026-08-11 10:00:00');

        MarketplaceMovementDateBackfill::run();

        $mv = StockMovement::where('reference_type', 'tiktok_order')->where('reference_id', $order->id)->first();
        $this->assertSame('2026-07-15', $mv->created_at->toDateString());  // di-floor ke cutoff, bukan 07-10
    }

    public function test_leaves_movement_when_order_date_null(): void
    {
        $p = $this->product('SKU-A');
        $order = TiktokOrder::create([
            'tiktok_order_id' => 'TTNULL', 'status' => 'COMPLETED', 'stock_status' => TiktokOrder::STATUS_DEDUCTED,
            'order_created_at' => null,
            'line_items' => [['sku' => 'SKU-A', 'name' => 'x', 'qty' => 1]],
        ]);
        $this->movement('tiktok_order', $order->id, $p->id, 'OUT', 1, 100, 99, '2026-08-11 10:00:00');

        MarketplaceMovementDateBackfill::run();

        $mv = StockMovement::where('reference_type', 'tiktok_order')->where('reference_id', $order->id)->first();
        $this->assertSame('2026-08-11', $mv->created_at->toDateString());  // tak berubah
    }

    public function test_is_idempotent(): void
    {
        $p = $this->product('SKU-A');
        $order = TiktokOrder::create([
            'tiktok_order_id' => 'TTIDEM', 'status' => 'COMPLETED', 'stock_status' => TiktokOrder::STATUS_DEDUCTED,
            'order_created_at' => '2026-08-05 09:00:00',
            'line_items' => [['sku' => 'SKU-A', 'name' => 'x', 'qty' => 3]],
        ]);
        $this->movement('tiktok_order', $order->id, $p->id, 'OUT', 3, 100, 97, '2026-08-11 10:00:00');

        MarketplaceMovementDateBackfill::run();
        MarketplaceMovementDateBackfill::run();   // dua kali

        $mv = StockMovement::where('reference_type', 'tiktok_order')->where('reference_id', $order->id)->first();
        $this->assertSame('2026-08-05', $mv->created_at->toDateString());
    }
}
