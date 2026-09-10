<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductWeightTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'sa', 'fullname' => 'Super', 'username' => 'sa', 'email' => 'sa@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'name' => 'Produk A', 'sku' => 'A1', 'status' => Product::STATUS_ACTIVE,
            'price_distributor' => 1000, 'price_reseller' => 1200, 'price_retail' => 1500,
            'price_grand' => 0, 'cogs' => 700, 'hq_stock' => 10,
        ], $override);
    }

    public function test_buat_produk_dengan_berat(): void
    {
        $this->actingAs($this->admin())
            ->post(route('products.store'), $this->payload(['weight_grams' => 250]))
            ->assertRedirect();

        $this->assertSame(250, (int) Product::where('sku', 'A1')->value('weight_grams'));
    }

    public function test_update_berat_produk(): void
    {
        $p = Product::create($this->payload(['weight_grams' => 100]));

        $this->actingAs($this->admin())
            ->put(route('products.update', $p), $this->payload(['weight_grams' => 500]))
            ->assertRedirect();

        $this->assertSame(500, (int) $p->fresh()->weight_grams);
    }

    public function test_berat_boleh_kosong(): void
    {
        // Berat opsional (default 0) — biar produk lama tak wajib diisi sekaligus.
        $this->actingAs($this->admin())
            ->post(route('products.store'), $this->payload(['sku' => 'B1']))
            ->assertRedirect();

        $this->assertSame(0, (int) Product::where('sku', 'B1')->value('weight_grams'));
    }
}
