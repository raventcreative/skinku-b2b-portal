<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductGrandColumnTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'A', 'fullname' => 'Admin', 'username' => 'adm', 'email' => 'adm@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_tabel_produk_ada_kolom_grand_dan_nilainya(): void
    {
        Product::create([
            'name' => 'Mizu', 'sku' => 'MZ-500ML',
            'price_distributor' => 29000, 'price_reseller' => 38000, 'price_retail' => 65000,
            'price_grand' => 26000, 'cogs' => 14000, 'hq_stock' => 10, 'status' => 'active',
        ]);

        $this->actingAs($this->admin())->get(route('products.index'))
            ->assertOk()
            ->assertSee('<th class="text-right">Grand</th>', false)       // header kolom (kunci <th>)
            ->assertSee('26.000');     // nilai harga Grand
    }

    public function test_produk_tanpa_price_grand_tampil_strip(): void
    {
        Product::create([
            'name' => 'Tanpa Grand', 'sku' => 'NG-1',
            'price_distributor' => 24000, 'price_reseller' => 29000, 'price_retail' => 39000,
            'price_grand' => null, 'cogs' => 10000, 'hq_stock' => 5, 'status' => 'active',
        ]);

        $this->actingAs($this->admin())->get(route('products.index'))
            ->assertOk()
            ->assertSee('—'); // em-dash untuk price_grand null
    }
}
