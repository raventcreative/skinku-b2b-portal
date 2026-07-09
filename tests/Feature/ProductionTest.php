<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\Product;
use App\Models\Production;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\MaterialService;
use App\Services\ProductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductionTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => "U{$n}", 'fullname' => "U{$n}", 'username' => "{$role}{$n}",
            'email' => "{$role}{$n}@skinku.test", 'password' => Hash::make('secret123'),
            'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function product(): Product
    {
        static $n = 0;
        $n++;

        return Product::create([
            'name' => "Produk {$n}", 'sku' => "SKU-{$n}",
            'price_distributor' => 40000, 'price_reseller' => 55000, 'price_retail' => 75000,
            'cogs' => 0, 'hq_stock' => 0, 'status' => Product::STATUS_ACTIVE,
        ]);
    }

    private function material(string $name, float $stock, float $cost, string $unit = 'pcs'): Material
    {
        return Material::create([
            'name' => $name, 'unit' => $unit, 'stock' => $stock, 'avg_cost' => $cost,
            'status' => Material::STATUS_ACTIVE,
        ]);
    }

    public function test_material_purchase_updates_stock_and_moving_average(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN));
        $m = $this->material('Botol', 0, 0);

        app(MaterialService::class)->addStock($m, 1000, 4000, null, 'Supplier A', '2026-06-01');
        $m->refresh();
        $this->assertEquals(1000, (float) $m->stock);
        $this->assertEquals(4000, (float) $m->avg_cost);

        // (1000*4000 + 1000*6000) / 2000 = 5000
        app(MaterialService::class)->addStock($m, 1000, 6000, null, 'Supplier A', '2026-06-05');
        $m->refresh();
        $this->assertEquals(2000, (float) $m->stock);
        $this->assertEquals(5000, (float) $m->avg_cost);
    }

    public function test_production_computes_hpp_and_updates_stock_cogs_and_materials(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN));
        $product = $this->product();
        $sabun = $this->material('Sabun', 1000, 10000);
        $botol = $this->material('Botol', 1000, 4000);

        $prod = app(ProductionService::class)->produce(
            ['product_id' => $product->id, 'produced_at' => '2026-06-10', 'output_qty' => 100],
            [
                ['material_id' => $sabun->id, 'quantity' => 100],
                ['material_id' => $botol->id, 'quantity' => 100],
            ],
            [['label' => 'Ongkir', 'amount' => 100000]],
        );

        // 100*10000 + 100*4000 + 100000 = 1.500.000 ; /100 = 15.000
        $this->assertEquals(1400000, (float) $prod->material_cost);
        $this->assertEquals(100000, (float) $prod->other_cost);
        $this->assertEquals(1500000, (float) $prod->total_cost);
        $this->assertEquals(15000, (float) $prod->hpp_per_unit);

        $product->refresh();
        $this->assertEquals(100, $product->hq_stock);
        $this->assertEquals(15000, (float) $product->cogs); // no prior basis -> hpp

        // Materials consumed.
        $this->assertEquals(900, (float) $sabun->refresh()->stock);
        $this->assertEquals(900, (float) $botol->refresh()->stock);

        // Finished-goods ledger row written.
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'movement_type' => StockMovement::TYPE_IN,
            'quantity' => 100,
            'reference_type' => Production::REFERENCE_TYPE,
            'reference_id' => $prod->id,
        ]);
    }

    public function test_production_cogs_is_moving_average_with_existing_stock(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN));
        $product = $this->product();
        $product->update(['hq_stock' => 100, 'cogs' => 25000]);
        $mat = $this->material('BahanX', 1000, 35000);

        // produce 100 @ hpp 35.000 -> avg (100*25000 + 100*35000)/200 = 30.000
        app(ProductionService::class)->produce(
            ['product_id' => $product->id, 'produced_at' => '2026-06-11', 'output_qty' => 100],
            [['material_id' => $mat->id, 'quantity' => 100]],
            [],
        );

        $product->refresh();
        $this->assertEquals(200, $product->hq_stock);
        $this->assertEquals(30000, (float) $product->cogs);
    }

    public function test_insufficient_material_blocks_production_over_http(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $product = $this->product();
        $mat = $this->material('Langka', 10, 5000);

        $this->actingAs($admin)->post('/productions', [
            'product_id' => $product->id,
            'produced_at' => '2026-06-12',
            'output_qty' => 50,
            'materials' => [['material_id' => $mat->id, 'quantity' => 100]],
        ])->assertSessionHasErrors('materials');

        $this->assertEquals(0, Production::count());
        $this->assertEquals(10, (float) $mat->refresh()->stock); // unchanged
        $this->assertEquals(0, $product->refresh()->hq_stock);
    }

    public function test_admin_can_post_production_over_http(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $product = $this->product();
        $mat = $this->material('Sabun', 500, 12000);

        $this->actingAs($admin)->post('/productions', [
            'product_id' => $product->id,
            'produced_at' => '2026-06-13',
            'output_qty' => 100,
            'materials' => [
                ['material_id' => $mat->id, 'quantity' => 100],
                ['material_id' => '', 'quantity' => ''], // blank ignored
            ],
            'costs' => [['label' => 'Ongkir', 'amount' => 200000]],
        ])->assertRedirect();

        $prod = Production::first();
        $this->assertNotNull($prod);
        // 100*12000 + 200000 = 1.400.000 ; /100 = 14.000
        $this->assertEquals(14000, (float) $prod->hpp_per_unit);
        $this->assertEquals(100, $product->refresh()->hq_stock);
        $this->assertEquals(400, (float) $mat->refresh()->stock);
    }

    public function test_production_create_page_renders(): void
    {
        $this->product();
        $this->material('Sabun', 100, 10000);
        $this->actingAs($this->user(User::ROLE_ADMIN))->get('/productions/create')
            ->assertOk()
            ->assertSee('JSON.parse(', false)   // JS data rendered raw, not HTML-escaped
            ->assertDontSee('&quot;id&quot;', false);
    }

    public function test_materials_index_renders(): void
    {
        $this->material('Sabun', 100, 10000);
        $this->actingAs($this->user(User::ROLE_ADMIN))->get('/materials')->assertOk();
    }

    public function test_reseller_cannot_access_production_and_materials(): void
    {
        $r = $this->user(User::ROLE_RESELLER);
        $this->actingAs($r)->get('/productions')->assertForbidden();
        $this->actingAs($r)->get('/productions/create')->assertForbidden();
        $this->actingAs($r)->get('/materials')->assertForbidden();
    }
}
