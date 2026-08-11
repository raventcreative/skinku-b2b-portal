<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class GrandPoCreatePageTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create([
            'name' => 'p', 'fullname' => 'P', 'username' => 'p', 'email' => 'p@skinku.test',
            'password' => Hash::make('secret123'), 'company_name' => 'CV P', 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function product(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Sabun', 'sku' => 'SB1',
            'price_distributor' => 24000, 'price_reseller' => 29000, 'price_retail' => 39000,
            'price_grand' => 22000, 'cogs' => 10000, 'hq_stock' => 100, 'status' => 'active',
        ], $overrides));
    }

    public function test_grand_lihat_harga_grand_di_form_create_po(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $this->product();

        $this->actingAs($grand)->get(route('purchase-orders.create'))
            ->assertOk()
            ->assertSee('22.000');   // harga Grand, bukan 24.000 (distributor)
    }
}
