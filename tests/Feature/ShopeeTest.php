<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ShopeeConnection;
use App\Models\ShopeeOrder;
use App\Models\ShopeeSkuMap;
use App\Models\User;
use App\Services\ShopeeClient;
use App\Services\ShopeeOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ShopeeTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => "S{$n}", 'fullname' => "S{$n}", 'username' => "{$role}s{$n}",
            'email' => "{$role}s{$n}@skinku.test", 'password' => Hash::make('secret123'),
            'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function configure(): void
    {
        config()->set('services.shopee.partner_id', '123456');
        config()->set('services.shopee.partner_key', 'secretkey');
    }

    private function product(float $cogs = 50000): Product
    {
        static $n = 0;
        $n++;

        return Product::create([
            'name' => "SP{$n}", 'sku' => "SPK-{$n}", 'hq_stock' => 100, 'status' => 'active',
            'cogs' => $cogs, 'price_distributor' => 1, 'price_reseller' => 1,
        ]);
    }

    public function test_signature_follows_shopee_concatenation_rule(): void
    {
        $this->configure();
        $c = app(ShopeeClient::class);

        // Shopee: rangkai BERURUTAN (bukan diurutkan by key seperti TikTok)
        $path = '/api/v2/order/get_order_list';
        $expected = hash_hmac('sha256', '123456'.$path.'1700000000'.'tok'.'shop9', 'secretkey');
        $this->assertSame($expected, $c->sign($path, 1700000000, 'tok', 'shop9'));

        // API publik: tanpa access_token & shop_id
        $pub = hash_hmac('sha256', '123456/api/v2/auth/token/get1700000000', 'secretkey');
        $this->assertSame($pub, $c->sign('/api/v2/auth/token/get', 1700000000));

        // urutan/isi berbeda → tanda tangan berbeda
        $this->assertNotSame($c->sign($path, 1700000000, 'tok', 'shop9'), $c->sign($path, 1700000001, 'tok', 'shop9'));
        $this->assertNotSame($c->sign($path, 1700000000, 'tok', 'shop9'), $c->sign($path, 1700000000, 'shop9', 'tok'));
    }

    public function test_normalizes_items_and_prefers_variant_sku(): void
    {
        $svc = app(ShopeeOrderService::class);

        $items = $svc->normalizeItems(['item_list' => [
            // varian (model_sku) diutamakan daripada item_sku
            ['item_sku' => 'INDUK', 'model_sku' => 'VAR-A', 'item_name' => 'Sabun', 'model_quantity_purchased' => 2],
            ['item_sku' => 'INDUK', 'model_sku' => 'VAR-A', 'item_name' => 'Sabun', 'model_quantity_purchased' => 3],
            // tanpa varian → jatuh ke item_sku
            ['item_sku' => 'POLOS', 'item_name' => 'Lotion', 'model_quantity_purchased' => 1],
        ]]);

        $this->assertCount(2, $items);
        $this->assertSame('VAR-A', $items[0]['sku']);
        $this->assertSame(5, $items[0]['qty']);   // 2+3 diagregasi
        $this->assertSame('POLOS', $items[1]['sku']);
    }

    public function test_recipe_maps_one_sku_to_many_products(): void
    {
        $svc = app(ShopeeOrderService::class);
        $a = $this->product();
        $b = $this->product();
        ShopeeSkuMap::create(['shopee_sku' => 'BUNDLE', 'product_id' => $a->id, 'qty' => 1]);
        ShopeeSkuMap::create(['shopee_sku' => 'BUNDLE', 'product_id' => $b->id, 'qty' => 3]);

        $comps = $svc->resolve('BUNDLE');

        $this->assertCount(2, $comps);
        $this->assertSame(3, $comps[1]['qty']);
    }

    public function test_deducts_stock_and_is_idempotent(): void
    {
        $svc = app(ShopeeOrderService::class);
        $p = $this->product();
        $o = ShopeeOrder::create([
            'order_sn' => 'SP1', 'status' => 'SHIPPED', 'stock_status' => ShopeeOrder::STATUS_PENDING,
            'total_amount' => 90000, 'order_created_at' => now(),
            'line_items' => [['sku' => $p->sku, 'name' => 'x', 'qty' => 4]],
        ]);

        $svc->deduct($o, $this->user(User::ROLE_ADMIN)->id);
        $svc->deduct($o->fresh());   // panggil lagi → tidak boleh dobel

        $this->assertEquals(96, $p->fresh()->hq_stock);          // 100 − 4 saja
        $this->assertEquals(200000, (float) $o->fresh()->hpp_amount); // 4 × 50rb, dikunci
        // stok keluar tercatat sebagai 'shopee_order' → Laporan Stok HQ mengisinya
        // ke kolom Shopee secara otomatis
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $p->id, 'movement_type' => 'OUT', 'reference_type' => 'shopee_order', 'quantity' => 4,
        ]);
    }

    public function test_cutoff_blocks_pre_opname_orders(): void
    {
        $svc = app(ShopeeOrderService::class);
        $p = $this->product();
        ShopeeConnection::create([
            'shop_id' => 'S9', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addHours(4), 'deduct_from' => '2026-07-15',
        ]);
        $mk = fn ($sn, $date) => ShopeeOrder::create([
            'order_sn' => $sn, 'status' => 'SHIPPED', 'stock_status' => ShopeeOrder::STATUS_PENDING,
            'order_created_at' => $date, 'line_items' => [['sku' => $p->sku, 'name' => 'x', 'qty' => 3]],
        ]);
        $mk('OLD', '2026-07-14 09:00');
        $mk('NEW', '2026-07-15 09:00');

        $r = $svc->deductAllReady($this->user(User::ROLE_ADMIN)->id);

        $this->assertSame(1, $r['done']);                 // hanya yang 15 Jul
        $this->assertEquals(97, $p->fresh()->hq_stock);   // 100 − 3, bukan −6
    }

    public function test_unshipped_and_cancelled_are_not_deducted(): void
    {
        $svc = app(ShopeeOrderService::class);
        $p = $this->product();
        foreach (['READY_TO_SHIP', 'CANCELLED', 'UNPAID'] as $i => $st) {
            ShopeeOrder::create([
                'order_sn' => "X{$i}", 'status' => $st, 'stock_status' => ShopeeOrder::STATUS_PENDING,
                'order_created_at' => now(), 'line_items' => [['sku' => $p->sku, 'name' => 'x', 'qty' => 5]],
            ]);
        }

        $r = $svc->deductAllReady();

        $this->assertSame(0, $r['done']);
        $this->assertEquals(100, $p->fresh()->hq_stock);   // stok utuh
    }

    public function test_reverse_returns_stock(): void
    {
        $svc = app(ShopeeOrderService::class);
        $p = $this->product();
        $o = ShopeeOrder::create([
            'order_sn' => 'REV', 'status' => 'COMPLETED', 'stock_status' => ShopeeOrder::STATUS_PENDING,
            'order_created_at' => now(), 'line_items' => [['sku' => $p->sku, 'name' => 'x', 'qty' => 7]],
        ]);

        $svc->deduct($o, $this->user(User::ROLE_ADMIN)->id);
        $this->assertEquals(93, $p->fresh()->hq_stock);

        $svc->reverse($o->fresh());
        $this->assertEquals(100, $p->fresh()->hq_stock);
        $this->assertSame(ShopeeOrder::STATUS_PENDING, $o->fresh()->stock_status);
    }

    public function test_store_keeps_deducted_flag_on_resync(): void
    {
        $svc = app(ShopeeOrderService::class);
        ShopeeOrder::create([
            'order_sn' => 'KEEP', 'status' => 'SHIPPED', 'stock_status' => ShopeeOrder::STATUS_DEDUCTED,
            'line_items' => [],
        ]);

        // tarik ulang order yang sama → status potong stok tidak boleh ter-reset
        $svc->store([['order_sn' => 'KEEP', 'order_status' => 'COMPLETED', 'total_amount' => 1]]);

        $o = ShopeeOrder::where('order_sn', 'KEEP')->first();
        $this->assertSame(ShopeeOrder::STATUS_DEDUCTED, $o->stock_status);
        $this->assertSame('COMPLETED', $o->status);   // status tetap diperbarui
    }
}
