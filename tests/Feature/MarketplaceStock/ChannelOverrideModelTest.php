<?php

namespace Tests\Feature\MarketplaceStock;

use App\Models\MarketplaceChannelOverride;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 1 (Fase 1.5): tabel & model marketplace_channel_overrides — override
 * stok manual per channel (tiktok/shopee) per produk, terpisah dari pool
 * marketplace_stocks. Unique per (product_id, channel).
 */
class ChannelOverrideModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_menyimpan_override_per_channel(): void
    {
        $p = Product::create(['name' => 'X', 'sku' => 'X-1', 'status' => 'active', 'price_distributor' => 1, 'price_reseller' => 1]);
        $o = MarketplaceChannelOverride::create(['product_id' => $p->id, 'channel' => 'tiktok', 'quantity' => 7, 'seeded_at' => now()]);

        $this->assertSame($p->id, $o->product->id);
        $this->assertSame(7, MarketplaceChannelOverride::where('product_id', $p->id)->where('channel', 'tiktok')->value('quantity'));
    }

    public function test_unik_per_produk_channel(): void
    {
        $p = Product::create(['name' => 'X', 'sku' => 'X-1', 'status' => 'active', 'price_distributor' => 1, 'price_reseller' => 1]);
        MarketplaceChannelOverride::create(['product_id' => $p->id, 'channel' => 'tiktok', 'quantity' => 1]);

        $this->expectException(QueryException::class);
        MarketplaceChannelOverride::create(['product_id' => $p->id, 'channel' => 'tiktok', 'quantity' => 2]);
    }
}
