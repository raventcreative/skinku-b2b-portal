<?php

namespace Tests\Unit\MarketplaceStock;

use App\Models\MarketplaceChannelOverride;
use App\Models\MarketplaceListing;
use App\Models\Product;
use App\Models\ShopeeSkuMap;
use App\Models\TiktokSkuMap;
use App\Services\MarketplaceStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 2 (Fase 1.5): stok efektif channel-aware + set/clear override per-channel.
 * Pola sama seperti AvailabilityTest (Fase 1) — override menang atas master,
 * channel lain tetap ikut master, clear balikin ke master lagi.
 */
class ChannelStockTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_channel_stock_override_menang_atas_master(): void
    {
        $p = $this->product();
        $this->svc()->setPool($p, 50); // master 50

        $this->assertSame(50, $this->svc()->channelStock($p->id, 'tiktok'));

        $this->svc()->setChannelOverride($p, 'tiktok', 8);

        $this->assertSame(8, $this->svc()->channelStock($p->id, 'tiktok')); // tiktok override
        $this->assertSame(50, $this->svc()->channelStock($p->id, 'shopee')); // shopee tetap master
    }

    public function test_channel_stock_null_ketika_belum_ada_master_maupun_override(): void
    {
        $p = $this->product();

        $this->assertNull($this->svc()->channelStock($p->id, 'tiktok'));
    }

    public function test_available_for_listing_channel_aware(): void
    {
        $p = $this->product('FM-1');
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        ShopeeSkuMap::create(['shopee_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        $svc = $this->svc();
        $svc->setPool($p, 20);
        $svc->setChannelOverride($p, 'tiktok', 5);
        $tt = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'FM-1']);
        $sp = MarketplaceListing::create(['channel' => 'shopee', 'seller_sku' => 'FM-1']);

        $this->assertSame(5, $svc->availableForListing($tt));   // pakai override tiktok
        $this->assertSame(20, $svc->availableForListing($sp));  // shopee tetap master
    }

    public function test_available_for_listing_bundle_pakai_override_per_komponen(): void
    {
        $a = $this->product();
        $b = $this->product();
        TiktokSkuMap::create(['tiktok_sku' => 'BND', 'product_id' => $a->id, 'qty' => 2]); // butuh 2 A
        TiktokSkuMap::create(['tiktok_sku' => 'BND', 'product_id' => $b->id, 'qty' => 1]); // + 1 B
        $svc = $this->svc();
        $svc->setPool($a, 10); // master A
        $svc->setPool($b, 3);  // master B
        $svc->setChannelOverride($a, 'tiktok', 4); // override A di tiktok -> floor(4/2)=2
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'BND']);

        $this->assertSame(2, $svc->availableForListing($l)); // min(floor(4/2)=2, floor(3/1)=3)
    }

    public function test_clear_override_balik_ikut_master(): void
    {
        $p = $this->product();
        $svc = $this->svc();
        $svc->setPool($p, 30);
        $svc->setChannelOverride($p, 'tiktok', 3);

        $svc->clearChannelOverride($p, 'tiktok');

        $this->assertSame(30, $svc->channelStock($p->id, 'tiktok'));
        $this->assertSame(0, MarketplaceChannelOverride::where('product_id', $p->id)->count());
    }

    public function test_set_channel_override_updates_existing_row_and_clamps_negative_to_zero(): void
    {
        $p = $this->product();
        $svc = $this->svc();
        $svc->setChannelOverride($p, 'tiktok', 10);

        $row = $svc->setChannelOverride($p, 'tiktok', -5);

        $this->assertInstanceOf(MarketplaceChannelOverride::class, $row);
        $this->assertSame(0, (int) $row->quantity);
        $this->assertSame(1, MarketplaceChannelOverride::where('product_id', $p->id)->where('channel', 'tiktok')->count());
    }

    public function test_clear_channel_override_hanya_hapus_channel_yang_diminta(): void
    {
        $p = $this->product();
        $svc = $this->svc();
        $svc->setPool($p, 30);
        $svc->setChannelOverride($p, 'tiktok', 3);
        $svc->setChannelOverride($p, 'shopee', 7);

        $svc->clearChannelOverride($p, 'tiktok');

        $this->assertSame(30, $svc->channelStock($p->id, 'tiktok')); // balik ke master
        $this->assertSame(7, $svc->channelStock($p->id, 'shopee'));  // shopee tak tersentuh
    }
}
