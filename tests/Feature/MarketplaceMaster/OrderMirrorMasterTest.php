<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\Product;
use App\Models\ShopeeOrder;
use App\Models\ShopeeSkuMap;
use App\Models\StockMovement;
use App\Models\TiktokOrder;
use App\Models\TiktokSkuMap;
use App\Services\ShopeeOrderService;
use App\Services\TikTokOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 6 (CUTOVER) — cermin "Produk Master" saat order MASUK, sekarang lewat
 * MarketplaceMasterService::applyOrderDelta() berbasis listing.master_id
 * (bukan lagi pool product-based MarketplaceStockService/MarketplaceStock,
 * yang diuji di tests/Feature/MarketplaceStock/OrderMirrorTest.php — file itu
 * dihapus di task ini). HQ (StockMovement via InventoryService::adjustHqStock,
 * dipicu deduct()/reverse() yang terpisah total dari store()/mirror) harus
 * tetap tercatat & sama sekali tak terpengaruh oleh rework ini.
 */
class OrderMirrorMasterTest extends TestCase
{
    use RefreshDatabase;

    private function masterWithListing(string $channel, string $sellerSku, int $baseStock): MarketplaceMaster
    {
        $m = MarketplaceMaster::create(['master_sku' => $sellerSku, 'name' => $sellerSku, 'base_stock' => $baseStock, 'seeded_at' => now()]);
        MarketplaceListing::create(['channel' => $channel, 'seller_sku' => $sellerSku, 'master_id' => $m->id, 'item_id' => 'ITEM-1']);

        return $m;
    }

    // ---------------------------------------------------------------
    // TikTok
    // ---------------------------------------------------------------

    public function test_tiktok_new_order_decrements_master_stock_not_hq(): void
    {
        $m = $this->masterWithListing('tiktok', 'FM-1', 10);
        $hqBefore = StockMovement::count();

        app(TikTokOrderService::class)->store([[
            'id' => 'O1', 'status' => 'AWAITING_SHIPMENT', 'create_time' => now()->addMinute()->timestamp,
            'payment' => ['total_amount' => 1, 'currency' => 'IDR'],
            'line_items' => [['seller_sku' => 'FM-1', 'quantity' => 3, 'product_name' => 'Face Mist']],
        ]]);

        $this->assertSame(7, $m->refresh()->base_stock); // 10-3
        $this->assertSame($hqBefore, StockMovement::count()); // store()/mirror sendiri tak sentuh HQ
    }

    public function test_tiktok_resync_same_order_does_not_double_decrement(): void
    {
        $m = $this->masterWithListing('tiktok', 'FM-1', 10);

        $order = [
            'id' => 'O1', 'status' => 'AWAITING_SHIPMENT', 'create_time' => now()->addMinute()->timestamp,
            'payment' => ['total_amount' => 1, 'currency' => 'IDR'],
            'line_items' => [['seller_sku' => 'FM-1', 'quantity' => 3, 'product_name' => 'Face Mist']],
        ];

        app(TikTokOrderService::class)->store([$order]);
        app(TikTokOrderService::class)->store([$order]); // re-sync, status tak berubah

        $this->assertSame(7, $m->refresh()->base_stock);
    }

    public function test_tiktok_order_before_seeded_at_is_ignored(): void
    {
        $m = $this->masterWithListing('tiktok', 'FM-1', 10);

        app(TikTokOrderService::class)->store([[
            'id' => 'OLD1', 'status' => 'AWAITING_SHIPMENT', 'create_time' => now()->subDay()->timestamp,
            'payment' => ['total_amount' => 1, 'currency' => 'IDR'],
            'line_items' => [['seller_sku' => 'FM-1', 'quantity' => 3, 'product_name' => 'Face Mist']],
        ]]);

        $this->assertSame(10, $m->refresh()->base_stock);
    }

    public function test_tiktok_transition_to_cancelled_restores_master_stock(): void
    {
        $m = $this->masterWithListing('tiktok', 'FM-1', 10);

        app(TikTokOrderService::class)->store([[
            'id' => 'O1', 'status' => 'AWAITING_SHIPMENT', 'create_time' => now()->addMinute()->timestamp,
            'payment' => ['total_amount' => 1, 'currency' => 'IDR'],
            'line_items' => [['seller_sku' => 'FM-1', 'quantity' => 3, 'product_name' => 'Face Mist']],
        ]]);
        $this->assertSame(7, $m->refresh()->base_stock);

        app(TikTokOrderService::class)->store([[
            'id' => 'O1', 'status' => 'CANCELLED', 'create_time' => now()->addMinute()->timestamp,
            'payment' => ['total_amount' => 1, 'currency' => 'IDR'],
            'line_items' => [['seller_sku' => 'FM-1', 'quantity' => 3, 'product_name' => 'Face Mist']],
        ]]);

        $this->assertSame(10, $m->refresh()->base_stock);
        $this->assertSame('CANCELLED', TiktokOrder::where('tiktok_order_id', 'O1')->value('status'));
    }

    /**
     * Order BARU yang lahir SUDAH batal tidak boleh menggeser stok master sama
     * sekali — bukan kurangi-lalu-kembalikan (sign harus 0, bukan -1 lalu +1).
     */
    public function test_tiktok_born_cancelled_order_leaves_master_stock_unchanged(): void
    {
        $m = $this->masterWithListing('tiktok', 'FM-1', 10);

        app(TikTokOrderService::class)->store([[
            'id' => 'OCX1', 'status' => 'CANCELLED', 'create_time' => now()->addMinute()->timestamp,
            'payment' => ['total_amount' => 1, 'currency' => 'IDR'],
            'line_items' => [['seller_sku' => 'FM-1', 'quantity' => 3, 'product_name' => 'Face Mist']],
        ]]);

        $this->assertSame(10, $m->refresh()->base_stock); // tak berubah
        $this->assertSame('CANCELLED', TiktokOrder::where('tiktok_order_id', 'OCX1')->value('status'));
    }

    // ---------------------------------------------------------------
    // Shopee
    // ---------------------------------------------------------------

    public function test_shopee_new_order_decrements_master_stock_not_hq(): void
    {
        $m = $this->masterWithListing('shopee', 'FM-1', 10);
        $hqBefore = StockMovement::count();

        app(ShopeeOrderService::class)->store([[
            'order_sn' => 'S1', 'order_status' => 'READY_TO_SHIP', 'total_amount' => 30000,
            'currency' => 'IDR', 'create_time' => now()->addMinute()->timestamp,
            'item_list' => [
                ['model_sku' => 'FM-1', 'item_name' => 'Face Mist', 'model_quantity_purchased' => 3, 'model_discounted_price' => 10000],
            ],
        ]]);

        $this->assertSame(7, $m->refresh()->base_stock);
        $this->assertSame($hqBefore, StockMovement::count());
    }

    public function test_shopee_resync_same_order_does_not_double_decrement(): void
    {
        $m = $this->masterWithListing('shopee', 'FM-1', 10);

        $order = [
            'order_sn' => 'S1', 'order_status' => 'READY_TO_SHIP', 'total_amount' => 30000,
            'currency' => 'IDR', 'create_time' => now()->addMinute()->timestamp,
            'item_list' => [
                ['model_sku' => 'FM-1', 'item_name' => 'Face Mist', 'model_quantity_purchased' => 3, 'model_discounted_price' => 10000],
            ],
        ];

        app(ShopeeOrderService::class)->store([$order]);
        app(ShopeeOrderService::class)->store([$order]);

        $this->assertSame(7, $m->refresh()->base_stock);
    }

    public function test_shopee_order_before_seeded_at_is_ignored(): void
    {
        $m = $this->masterWithListing('shopee', 'FM-1', 10);

        app(ShopeeOrderService::class)->store([[
            'order_sn' => 'SOLD1', 'order_status' => 'READY_TO_SHIP', 'total_amount' => 30000,
            'currency' => 'IDR', 'create_time' => now()->subDay()->timestamp,
            'item_list' => [
                ['model_sku' => 'FM-1', 'item_name' => 'Face Mist', 'model_quantity_purchased' => 3, 'model_discounted_price' => 10000],
            ],
        ]]);

        $this->assertSame(10, $m->refresh()->base_stock);
    }

    public function test_shopee_transition_to_cancelled_restores_master_stock(): void
    {
        $m = $this->masterWithListing('shopee', 'FM-1', 10);

        $base = [
            'order_sn' => 'S1', 'total_amount' => 30000, 'currency' => 'IDR',
            'create_time' => now()->addMinute()->timestamp,
            'item_list' => [
                ['model_sku' => 'FM-1', 'item_name' => 'Face Mist', 'model_quantity_purchased' => 3, 'model_discounted_price' => 10000],
            ],
        ];

        app(ShopeeOrderService::class)->store([['order_status' => 'READY_TO_SHIP'] + $base]);
        $this->assertSame(7, $m->refresh()->base_stock);

        app(ShopeeOrderService::class)->store([['order_status' => 'CANCELLED'] + $base]);

        $this->assertSame(10, $m->refresh()->base_stock);
        $this->assertSame('CANCELLED', ShopeeOrder::where('order_sn', 'S1')->value('status'));
    }

    public function test_shopee_born_cancelled_order_leaves_master_stock_unchanged(): void
    {
        $m = $this->masterWithListing('shopee', 'FM-1', 10);

        app(ShopeeOrderService::class)->store([[
            'order_sn' => 'SCX1', 'order_status' => 'CANCELLED', 'total_amount' => 30000,
            'currency' => 'IDR', 'create_time' => now()->addMinute()->timestamp,
            'item_list' => [
                ['model_sku' => 'FM-1', 'item_name' => 'Face Mist', 'model_quantity_purchased' => 3, 'model_discounted_price' => 10000],
            ],
        ]]);

        $this->assertSame(10, $m->refresh()->base_stock); // tak berubah
        $this->assertSame('CANCELLED', ShopeeOrder::where('order_sn', 'SCX1')->value('status'));
    }

    // ---------------------------------------------------------------
    // HQ tetap tercatat — cutover cuma ganti DI marketplace di kedua
    // OrderService, jalur potong stok HQ (resep SkuMap → adjustHqStock)
    // harus tetap utuh & tak terpengaruh sama sekali.
    // ---------------------------------------------------------------

    public function test_tiktok_deduct_masih_mencatat_stock_movement_hq_setelah_cutover(): void
    {
        $p = Product::create([
            'name' => 'Face Mist', 'sku' => 'FM-1', 'status' => 'active',
            'price_distributor' => 1, 'price_reseller' => 1, 'hq_stock' => 100, 'cogs' => 1000,
        ]);
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        // Listing+master ada berdampingan (mirror jalan jua), tapi HQ pakai jalur resolve()/SkuMap sendiri.
        $this->masterWithListing('tiktok', 'FM-1', 10);
        $hqBefore = StockMovement::count();

        $svc = app(TikTokOrderService::class);
        $svc->store([[
            'id' => 'O1', 'status' => 'AWAITING_COLLECTION', 'create_time' => now()->addMinute()->timestamp,
            'payment' => ['total_amount' => 1, 'currency' => 'IDR'],
            'line_items' => [['seller_sku' => 'FM-1', 'quantity' => 3, 'product_name' => 'Face Mist']],
        ]]);
        $order = TiktokOrder::where('tiktok_order_id', 'O1')->firstOrFail();
        $svc->deduct($order);

        $this->assertSame($hqBefore + 1, StockMovement::count());
        $this->assertSame(97, $p->refresh()->hq_stock); // 100-3
        $this->assertSame(TiktokOrder::STATUS_DEDUCTED, $order->refresh()->stock_status);
    }

    public function test_shopee_deduct_masih_mencatat_stock_movement_hq_setelah_cutover(): void
    {
        $p = Product::create([
            'name' => 'Face Mist', 'sku' => 'FM-1', 'status' => 'active',
            'price_distributor' => 1, 'price_reseller' => 1, 'hq_stock' => 100, 'cogs' => 1000,
        ]);
        ShopeeSkuMap::create(['shopee_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        $this->masterWithListing('shopee', 'FM-1', 10);
        $hqBefore = StockMovement::count();

        $svc = app(ShopeeOrderService::class);
        $svc->store([[
            'order_sn' => 'S1', 'order_status' => 'SHIPPED', 'total_amount' => 30000,
            'currency' => 'IDR', 'create_time' => now()->addMinute()->timestamp,
            'item_list' => [
                ['model_sku' => 'FM-1', 'item_name' => 'Face Mist', 'model_quantity_purchased' => 3, 'model_discounted_price' => 10000],
            ],
        ]]);
        $order = ShopeeOrder::where('order_sn', 'S1')->firstOrFail();
        $svc->deduct($order);

        $this->assertSame($hqBefore + 1, StockMovement::count());
        $this->assertSame(97, $p->refresh()->hq_stock); // 100-3
        $this->assertSame(ShopeeOrder::STATUS_DEDUCTED, $order->refresh()->stock_status);
    }

    /**
     * Divergensi YANG DISENGAJA antara mirror marketplace & potong HQ untuk SKU
     * bundle: mirror (applyOrderDelta via listing.master_id) mengurangi master
     * sebesar qty ORDER MENTAH (1 unit listing = 1 unit master, tak kenal resep),
     * sedangkan HQ (resolve() via TiktokSkuMap → adjustHqStock) mengurangi
     * komponen sebesar qty ORDER × qty RESEP. Product.sku ('BND-P') sengaja BEDA
     * dari master_sku/seller_sku/tiktok_sku ('BND') — membuktikan dua jalur
     * resolve itu independen (listing dicari by seller_sku, bukan Product.sku).
     */
    public function test_tiktok_mirror_master_pakai_qty_mentah_hq_pakai_resep_skumap_bundle(): void
    {
        $p = Product::create([
            'name' => 'Bundle Hemat', 'sku' => 'BND-P', 'status' => 'active',
            'price_distributor' => 1, 'price_reseller' => 1, 'hq_stock' => 100, 'cogs' => 1000,
        ]);
        TiktokSkuMap::create(['tiktok_sku' => 'BND', 'product_id' => $p->id, 'qty' => 2]); // resep: 1 listing = 2 fisik
        $m = $this->masterWithListing('tiktok', 'BND', 50);
        $hqBefore = StockMovement::count();

        $svc = app(TikTokOrderService::class);
        $svc->store([[
            'id' => 'OBND1', 'status' => 'AWAITING_COLLECTION', 'create_time' => now()->addMinute()->timestamp,
            'payment' => ['total_amount' => 1, 'currency' => 'IDR'],
            'line_items' => [['seller_sku' => 'BND', 'quantity' => 3, 'product_name' => 'Bundle Hemat']],
        ]]);

        // Mirror: master turun sebesar qty MENTAH order (3), BUKAN ×2.
        $this->assertSame(47, $m->refresh()->base_stock); // 50-3

        $order = TiktokOrder::where('tiktok_order_id', 'OBND1')->firstOrFail();
        $svc->deduct($order);

        // HQ: komponen turun sebesar qty order × qty resep (3×2=6), tercatat StockMovement.
        $this->assertSame($hqBefore + 1, StockMovement::count());
        $this->assertSame(94, $p->refresh()->hq_stock); // 100-6
        $this->assertSame(TiktokOrder::STATUS_DEDUCTED, $order->refresh()->stock_status);
    }
}
