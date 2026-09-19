<?php

namespace Tests\Feature\MarketplaceStock;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceStock;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplaceModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_stock_pool_per_product_and_listing_per_channel(): void
    {
        $p = Product::create([
            'name' => 'Face Mist', 'sku' => 'FM-1', 'status' => 'active',
            'price_distributor' => 1, 'price_reseller' => 1,
        ]);

        $pool = MarketplaceStock::create(['product_id' => $p->id, 'quantity' => 50, 'seeded_at' => now()]);
        $this->assertSame($p->id, $pool->product->id);

        $l = MarketplaceListing::create([
            'channel' => 'tiktok', 'seller_sku' => 'FM-1', 'item_id' => '111', 'variation_id' => '222',
            'warehouse_id' => 'W1', 'title' => 'Face Mist', 'last_status' => 'ok', 'last_pushed_qty' => 50,
        ]);
        $this->assertSame('tiktok', $l->channel);
    }
}
