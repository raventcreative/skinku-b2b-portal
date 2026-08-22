<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ShopeeOrder;
use App\Models\ShopeeSkuMap;
use App\Models\User;
use App\Services\ShopeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ShopeeWiringTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => 'u', 'fullname' => 'U', 'username' => 'u'.uniqid(),
            'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'),
            'role' => $role, 'status' => User::STATUS_ACTIVE]);
    }

    public function test_admin_bisa_buka_shopee_index(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN))->get(route('shopee.index'))
            ->assertOk()->assertSee('Integrasi Shopee');
    }

    public function test_mitra_tak_boleh_akses(): void
    {
        $this->actingAs($this->user(User::ROLE_DISTRIBUTOR))->get('/shopee')->assertForbidden();
    }

    public function test_callback_menyimpan_koneksi(): void
    {
        // fake ShopeeClient: getToken balikin token kaleng
        $fake = new class extends ShopeeClient
        {
            public function __construct() {}

            public function configured(): bool
            {
                return true;
            }

            public function getToken(string $code, string $shopId): array
            {
                return ['access_token' => 'A', 'refresh_token' => 'R', 'expire_in' => 14400];
            }
        };
        $this->app->instance(ShopeeClient::class, $fake);

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->get(route('shopee.callback', ['code' => 'xyz', 'shop_id' => '777']))
            ->assertRedirect(route('shopee.index'));

        $this->assertDatabaseHas('shopee_connections', ['shop_id' => '777', 'access_token' => 'A']);
    }

    public function test_deduct_satu_order(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $p = Product::create(['name' => 'Sabun', 'sku' => 'SB1', 'price_grand' => 1,
            'price_distributor' => 1, 'price_reseller' => 1, 'price_retail' => 1, 'cogs' => 1,
            'hq_stock' => 100, 'status' => 'active']);
        ShopeeSkuMap::create(['shopee_sku' => 'SB1', 'product_id' => $p->id, 'qty' => 1]);
        $o = ShopeeOrder::create(['order_sn' => 'D1', 'status' => 'SHIPPED', 'total_amount' => 1,
            'line_items' => [['sku' => 'SB1', 'name' => 'Sabun', 'qty' => 3]], 'stock_status' => ShopeeOrder::STATUS_PENDING,
            'order_created_at' => now()]);

        $this->actingAs($admin)->post(route('shopee.deduct', $o))->assertRedirect();
        $this->assertSame(97, (int) $p->fresh()->hq_stock);
    }

    public function test_command_shopee_sync_tanpa_koneksi_aman(): void
    {
        $this->artisan('shopee:sync')->assertSuccessful();
    }

    public function test_halaman_orders_dan_stok_render(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->actingAs($admin)->get(route('shopee.orders'))->assertOk()->assertSee('Order Shopee');
        $this->actingAs($admin)->get(route('shopee.stock'))->assertOk();
    }
}
