<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ShopeeReturn;
use App\Models\ShopeeSkuMap;
use App\Models\User;
use App\Services\ShopeeReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ShopeeReturnTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_menyimpan_dan_cast_line_items(): void
    {
        $r = ShopeeReturn::create([
            'shopee_return_sn' => 'R-1',
            'shopee_order_sn' => 'S-1',
            'status' => 'ACCEPTED',
            'return_reason' => 'Rusak',
            'line_items' => [['sku' => 'A', 'name' => 'Produk A', 'qty' => 2]],
            'review_status' => ShopeeReturn::REVIEW_PENDING,
        ]);

        $this->assertSame('R-1', $r->shopee_return_sn);
        $this->assertSame('pending', $r->review_status);
        $this->assertIsArray($r->fresh()->line_items);
        $this->assertSame(2, $r->fresh()->line_items[0]['qty']);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin Retur', 'fullname' => 'Admin Retur', 'username' => 'shopeereturnadmin',
            'email' => 'shopeereturnadmin@skinku.test', 'password' => Hash::make('secret123'),
            'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function product(int $stock = 100): Product
    {
        return Product::create([
            'name' => 'Produk Retur', 'sku' => 'RTR-1', 'hq_stock' => $stock, 'status' => 'active',
            'cogs' => 30000, 'price_distributor' => 1, 'price_reseller' => 1,
        ]);
    }

    private function returnFor(Product $p, int $qty = 2, string $sn = 'R-10'): ShopeeReturn
    {
        ShopeeSkuMap::firstOrCreate(['shopee_sku' => 'RSKU', 'product_id' => $p->id], ['qty' => 1]);

        return ShopeeReturn::create([
            'shopee_return_sn' => $sn, 'shopee_order_sn' => 'S-10', 'status' => 'ACCEPTED',
            'line_items' => [['sku' => 'RSKU', 'name' => 'Produk Retur', 'qty' => $qty]],
            'review_status' => ShopeeReturn::REVIEW_PENDING,
        ]);
    }

    public function test_restock_menambah_stok_reject_tidak_reset_menarik_kembali(): void
    {
        $p = $this->product(100);
        $admin = $this->admin();
        $svc = app(ShopeeReturnService::class);

        $ret = $this->returnFor($p, 2, 'R-A');
        $svc->restock($ret, $admin->id);
        $this->assertEquals(102, $p->fresh()->hq_stock);
        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => 'shopee_return', 'reference_id' => $ret->id,
        ]);
        $this->assertSame(ShopeeReturn::REVIEW_RESTOCKED, $ret->fresh()->review_status);

        // restock lagi = idempoten (tak dobel)
        $svc->restock($ret->fresh(), $admin->id);
        $this->assertEquals(102, $p->fresh()->hq_stock);

        // reject retur lain (pending) tak ubah stok
        $ret2 = $this->returnFor($p, 5, 'R-B');
        $svc->reject($ret2, $admin->id);
        $this->assertEquals(102, $p->fresh()->hq_stock);
        $this->assertSame(ShopeeReturn::REVIEW_REJECTED, $ret2->fresh()->review_status);

        // reset yang sudah restocked → tarik stok lagi
        $svc->resetReview($ret->fresh());
        $this->assertEquals(100, $p->fresh()->hq_stock);
        $this->assertSame(ShopeeReturn::REVIEW_PENDING, $ret->fresh()->review_status);
    }
}
