<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductGrandPriceFormTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'A', 'fullname' => 'Admin', 'username' => 'adm', 'email' => 'adm@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sabun', 'sku' => 'SB1',
            'price_distributor' => 24000, 'price_reseller' => 29000, 'price_retail' => 39000,
            'cogs' => 10000, 'hq_stock' => 100, 'status' => 'active',
        ], $overrides);
    }

    public function test_simpan_produk_dengan_price_grand(): void
    {
        $this->actingAs($this->admin())->post(route('products.store'), $this->payload(['price_grand' => 22000]))
            ->assertRedirect();

        $this->assertEqualsWithDelta(22000, (float) Product::first()->price_grand, 0.01);
    }

    public function test_price_grand_boleh_kosong_null(): void
    {
        $this->actingAs($this->admin())->post(route('products.store'), $this->payload())
            ->assertRedirect();

        $this->assertNull(Product::first()->price_grand);
    }

    public function test_update_price_grand(): void
    {
        $p = Product::create($this->payload(['sku' => 'SB2', 'price_grand' => 22000]));

        $this->actingAs($this->admin())->put(route('products.update', $p), $this->payload(['sku' => 'SB2', 'price_grand' => 21000]))
            ->assertRedirect();

        $this->assertEqualsWithDelta(21000, (float) $p->fresh()->price_grand, 0.01);
    }
}
