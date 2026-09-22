<?php

namespace Tests\Unit\MarketplaceStock;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceStock;
use App\Models\Product;
use App\Models\ShopeeSkuMap;
use App\Models\TiktokSkuMap;
use App\Services\MarketplaceStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private int $seq = 0;

    private function product(?string $sku = null): Product
    {
        return Product::create([
            'name' => 'Produk '.(++$this->seq),
            'sku' => $sku ?? ('SKU-'.$this->seq),
            'status' => 'active',
            'price_distributor' => 1,
            'price_reseller' => 1,
        ]);
    }

    private function svc(): MarketplaceStockService
    {
        return app(MarketplaceStockService::class);
    }

    public function test_one_to_one_mapping_available_equals_pool(): void
    {
        $p = $this->product('FM-1');
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        MarketplaceStock::create(['product_id' => $p->id, 'quantity' => 50, 'seeded_at' => now()]);
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'FM-1']);

        $this->assertSame(50, $this->svc()->availableForListing($l));
    }

    public function test_bundle_available_is_min_of_floor_pool_over_qty(): void
    {
        $a = $this->product();
        $b = $this->product();
        TiktokSkuMap::create(['tiktok_sku' => 'BND', 'product_id' => $a->id, 'qty' => 2]); // butuh 2 A
        TiktokSkuMap::create(['tiktok_sku' => 'BND', 'product_id' => $b->id, 'qty' => 1]); // + 1 B
        MarketplaceStock::create(['product_id' => $a->id, 'quantity' => 10, 'seeded_at' => now()]); // floor(10/2)=5
        MarketplaceStock::create(['product_id' => $b->id, 'quantity' => 3, 'seeded_at' => now()]);  // floor(3/1)=3
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'BND']);

        $this->assertSame(3, $this->svc()->availableForListing($l)); // min(5,3)
    }

    public function test_null_when_unmapped_or_pool_not_yet_seeded(): void
    {
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'GHOST']);
        $this->assertNull($this->svc()->availableForListing($l));

        $p = $this->product('X');
        TiktokSkuMap::create(['tiktok_sku' => 'X', 'product_id' => $p->id, 'qty' => 1]);
        $l2 = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'X']); // ada map, TAPI pool belum ada
        $this->assertNull($this->svc()->availableForListing($l2));
    }

    public function test_apply_order_delta_respects_seeded_at_and_clamps_to_zero(): void
    {
        $p = $this->product();
        MarketplaceStock::create(['product_id' => $p->id, 'quantity' => 5, 'seeded_at' => now()]);

        $this->svc()->applyOrderDelta($p, 'tiktok', -2, now()->addMinute()); // sesudah seed -> 3 (tak ada override -> ke master)
        $this->assertSame(3, (int) MarketplaceStock::where('product_id', $p->id)->value('quantity'));

        $this->svc()->applyOrderDelta($p, 'tiktok', -1, now()->subDay()); // sebelum seed -> diabaikan
        $this->assertSame(3, (int) MarketplaceStock::where('product_id', $p->id)->value('quantity'));

        $this->svc()->applyOrderDelta($p, 'tiktok', -99, now()->addMinute()); // clamp
        $this->assertSame(0, (int) MarketplaceStock::where('product_id', $p->id)->value('quantity'));
    }

    public function test_available_for_listing_uses_shopee_sku_map_for_shopee_channel(): void
    {
        $p = $this->product('SP-1');
        ShopeeSkuMap::create(['shopee_sku' => 'SP-1', 'product_id' => $p->id, 'qty' => 1]);
        MarketplaceStock::create(['product_id' => $p->id, 'quantity' => 20, 'seeded_at' => now()]);
        $l = MarketplaceListing::create(['channel' => 'shopee', 'seller_sku' => 'SP-1']);

        $this->assertSame(20, $this->svc()->availableForListing($l));
    }

    public function test_components_for_falls_back_to_product_sku_when_unmapped(): void
    {
        $p = $this->product('PLAIN-SKU');

        $components = $this->svc()->componentsFor('tiktok', 'PLAIN-SKU');

        $this->assertSame([['product_id' => $p->id, 'qty' => 1]], $components);
    }

    public function test_components_for_returns_empty_array_when_nothing_matches(): void
    {
        $this->assertSame([], $this->svc()->componentsFor('tiktok', 'NOPE'));
    }

    public function test_set_pool_creates_row_and_stamps_seeded_at(): void
    {
        Carbon::setTestNow('2026-09-19 08:00:00');
        $p = $this->product();

        $row = $this->svc()->setPool($p, 15);

        $this->assertInstanceOf(MarketplaceStock::class, $row);
        $this->assertSame(15, (int) $row->quantity);
        $this->assertSame('2026-09-19 08:00:00', $row->seeded_at->toDateTimeString());
        $this->assertSame(1, MarketplaceStock::where('product_id', $p->id)->count());
    }

    public function test_set_pool_updates_existing_row_and_clamps_negative_qty_to_zero(): void
    {
        $p = $this->product();
        MarketplaceStock::create(['product_id' => $p->id, 'quantity' => 10, 'seeded_at' => now()->subDay()]);

        $row = $this->svc()->setPool($p, -5);

        $this->assertSame(1, MarketplaceStock::where('product_id', $p->id)->count());
        $this->assertSame(0, (int) $row->quantity);
    }

    public function test_adjust_pool_increments_and_decrements_existing_row(): void
    {
        $p = $this->product();
        MarketplaceStock::create(['product_id' => $p->id, 'quantity' => 10, 'seeded_at' => now()]);

        $this->svc()->adjustPool($p, -3);
        $this->assertSame(7, (int) MarketplaceStock::where('product_id', $p->id)->value('quantity'));

        $this->svc()->adjustPool($p, 5);
        $this->assertSame(12, (int) MarketplaceStock::where('product_id', $p->id)->value('quantity'));
    }

    public function test_adjust_pool_clamps_at_zero(): void
    {
        $p = $this->product();
        MarketplaceStock::create(['product_id' => $p->id, 'quantity' => 2, 'seeded_at' => now()]);

        $this->svc()->adjustPool($p, -10);

        $this->assertSame(0, (int) MarketplaceStock::where('product_id', $p->id)->value('quantity'));
    }

    public function test_adjust_pool_no_ops_when_no_pool_row_exists(): void
    {
        $p = $this->product();

        $this->svc()->adjustPool($p, -3);

        $this->assertSame(0, MarketplaceStock::where('product_id', $p->id)->count());
    }
}
