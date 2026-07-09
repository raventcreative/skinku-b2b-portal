<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\MaterialPurchase;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SupplierMaterialTest extends TestCase
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

    private function material(float $stock, float $cost): Material
    {
        return Material::create(['name' => 'Sabun', 'unit' => 'kg', 'stock' => $stock, 'avg_cost' => $cost, 'status' => 'active']);
    }

    public function test_admin_can_manage_suppliers(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->actingAs($admin)->get('/suppliers')->assertOk();
        $this->actingAs($admin)->post('/suppliers', ['name' => 'JJ TOP', 'phone' => '0812'])->assertRedirect();
        $this->assertDatabaseHas('suppliers', ['name' => 'JJ TOP']);
    }

    public function test_reseller_cannot_access_suppliers(): void
    {
        $this->actingAs($this->user(User::ROLE_RESELLER))->get('/suppliers')->assertForbidden();
    }

    public function test_purchase_average_mode_blends_cost(): void
    {
        $m = $this->material(100, 10000);
        $this->actingAs($this->user(User::ROLE_ADMIN))->post('/materials/purchase', [
            'material_id' => $m->id, 'quantity' => 100, 'unit_cost' => 40000,
            'cost_mode' => 'average', 'purchased_at' => '2026-07-01',
        ])->assertRedirect();

        // (100*10000 + 100*40000)/200 = 25000
        $this->assertEquals(200, (float) $m->refresh()->stock);
        $this->assertEquals(25000, (float) $m->avg_cost);
    }

    public function test_purchase_direct_mode_sets_exact_cost(): void
    {
        $m = $this->material(100, 10000);
        $supplier = Supplier::create(['name' => 'JJ TOP', 'status' => 'active']);

        $this->actingAs($this->user(User::ROLE_ADMIN))->post('/materials/purchase', [
            'material_id' => $m->id, 'quantity' => 100, 'unit_cost' => 40000,
            'cost_mode' => 'direct', 'supplier_id' => $supplier->id, 'purchased_at' => '2026-07-01',
        ])->assertRedirect();

        // direct: avg_cost becomes exactly 40000 (not blended); stock still rises
        $this->assertEquals(200, (float) $m->refresh()->stock);
        $this->assertEquals(40000, (float) $m->avg_cost);

        $purchase = MaterialPurchase::first();
        $this->assertEquals($supplier->id, $purchase->supplier_id);
        $this->assertEquals('JJ TOP', $purchase->supplier_name);
    }

    public function test_admin_can_edit_material_hpp_manually(): void
    {
        $m = $this->material(100, 10000);
        $this->actingAs($this->user(User::ROLE_ADMIN))->put('/materials/'.$m->id, [
            'name' => 'Sabun', 'unit' => 'kg', 'status' => 'active', 'avg_cost' => 12345,
        ])->assertRedirect();

        $this->assertEquals(12345, (float) $m->refresh()->avg_cost);
    }

    public function test_editing_material_without_hpp_keeps_existing(): void
    {
        $m = $this->material(100, 9999);
        $this->actingAs($this->user(User::ROLE_ADMIN))->put('/materials/'.$m->id, [
            'name' => 'Sabun', 'unit' => 'kg', 'status' => 'active', 'avg_cost' => '',
        ])->assertRedirect();

        $this->assertEquals(9999, (float) $m->refresh()->avg_cost); // unchanged
    }

    public function test_materials_page_renders_with_supplier_dropdown(): void
    {
        Supplier::create(['name' => 'JJ TOP', 'status' => 'active']);
        $this->material(100, 10000);
        $this->actingAs($this->user(User::ROLE_ADMIN))->get('/materials')
            ->assertOk()
            ->assertSee('JJ TOP');
    }
}
