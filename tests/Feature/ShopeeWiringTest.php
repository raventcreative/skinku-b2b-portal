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

        // DB kosong dulu: pastikan state kosong (belum ada order/SKU) aman dirender.
        $this->actingAs($admin)->get(route('shopee.orders'))->assertOk()->assertSee('Order Shopee');
        $this->actingAs($admin)->get(route('shopee.stock'))->assertOk();

        // Seed: produk + peta SKU + 3 order (siap potong / sudah dipotong / SKU belum
        // dipetakan) supaya baris tabel order (pratinjau + tombol potong) dan kartu
        // $needMap benar-benar dirender, bukan cuma lewat state kosong.
        $p = Product::create(['name' => 'Sabun', 'sku' => 'SB1', 'price_grand' => 1,
            'price_distributor' => 1, 'price_reseller' => 1, 'price_retail' => 1, 'cogs' => 1,
            'hq_stock' => 100, 'status' => 'active']);
        ShopeeSkuMap::create(['shopee_sku' => 'SB1', 'product_id' => $p->id, 'qty' => 1]);

        $ready = ShopeeOrder::create(['order_sn' => 'READY1', 'status' => 'SHIPPED', 'total_amount' => 50000,
            'line_items' => [['sku' => 'SB1', 'name' => 'Sabun', 'qty' => 2]],
            'stock_status' => ShopeeOrder::STATUS_PENDING, 'order_created_at' => now()]);

        ShopeeOrder::create(['order_sn' => 'DONE1', 'status' => 'COMPLETED', 'total_amount' => 30000,
            'line_items' => [['sku' => 'SB1', 'name' => 'Sabun', 'qty' => 1]],
            'stock_status' => ShopeeOrder::STATUS_DEDUCTED, 'order_created_at' => now()]);

        ShopeeOrder::create(['order_sn' => 'UNMAP1', 'status' => 'SHIPPED', 'total_amount' => 20000,
            'line_items' => [['sku' => 'SB-UNMAPPED', 'name' => 'Lotion', 'qty' => 1]],
            'stock_status' => ShopeeOrder::STATUS_PENDING, 'order_created_at' => now()]);

        $this->actingAs($admin)->get(route('shopee.orders'))->assertOk()
            ->assertSee('Order Shopee')->assertSee($ready->order_sn);
        // Pemetaan SKU ada di halaman SKU & Stok (bukan lagi di halaman integrasi).
        $this->actingAs($admin)->get(route('shopee.stock'))->assertOk()->assertSee('SB-UNMAPPED');
        $this->actingAs($admin)->get(route('shopee.index'))->assertOk();
    }

    public function test_label_sku_bedakan_sudah_vs_belum_dipetakan(): void
    {
        // SKU yang SUDAH dipetakan manual tak boleh lagi ke-hitung "perlu dipetakan".
        $admin = $this->user(User::ROLE_ADMIN);

        // Produk target peta; sku-nya beda dari SKU Shopee → tak auto-match.
        $prod = Product::create(['name' => 'Lotion Asli', 'sku' => 'RP1', 'price_grand' => 1,
            'price_distributor' => 1, 'price_reseller' => 1, 'price_retail' => 1, 'cogs' => 1,
            'hq_stock' => 100, 'status' => 'active']);
        ShopeeSkuMap::create(['shopee_sku' => 'MAPPED-SKU', 'product_id' => $prod->id, 'qty' => 1]);

        ShopeeOrder::create(['order_sn' => 'M1', 'status' => 'SHIPPED', 'total_amount' => 10000,
            'line_items' => [['sku' => 'MAPPED-SKU', 'name' => 'Lotion', 'qty' => 1]],
            'stock_status' => ShopeeOrder::STATUS_PENDING, 'order_created_at' => now()]);
        ShopeeOrder::create(['order_sn' => 'R1', 'status' => 'SHIPPED', 'total_amount' => 10000,
            'line_items' => [['sku' => 'RAW-SKU', 'name' => 'Serum', 'qty' => 1]],
            'stock_status' => ShopeeOrder::STATUS_PENDING, 'order_created_at' => now()]);

        $this->actingAs($admin)->get(route('shopee.stock'))->assertOk()
            ->assertSee('perlu dipetakan')   // header cuma hitung yang belum (1: RAW-SKU)
            ->assertSee('belum ada resep')    // RAW-SKU = belum
            ->assertSee('Lotion Asli');       // MAPPED-SKU tampil resepnya (sudah dipetakan)
    }
}
