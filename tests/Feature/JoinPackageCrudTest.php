<?php

namespace Tests\Feature;

use App\Models\JoinPackage;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class JoinPackageCrudTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        static $n = 0;
        $n++;

        return User::create(['name' => "u$n", 'fullname' => "U$n", 'username' => "u$n", 'email' => "u$n@t.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE]);
    }

    private function product(): Product
    {
        static $n = 0;
        $n++;

        return Product::create(['name' => "P$n", 'sku' => "SKU-$n", 'price_distributor' => 24000,
            'price_reseller' => 29000, 'price_retail' => 39000, 'cogs' => 10000, 'hq_stock' => 100,
            'status' => Product::STATUS_ACTIVE]);
    }

    public function test_admin_buat_paket_dengan_item(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $p = $this->product();

        $this->actingAs($admin)->post(route('join-packages.store'), [
            'name' => 'Bronze', 'target_role' => User::ROLE_RESELLER_BRONZE, 'price' => 149000, 'is_active' => 1,
            'items' => [['product_id' => $p->id, 'qty' => 3]],
        ])->assertRedirect();

        $paket = JoinPackage::first();
        $this->assertSame('Bronze', $paket->name);
        $this->assertCount(1, $paket->items);
        $this->assertSame(3, $paket->items->first()->qty);
    }

    public function test_mitra_tak_bisa_akses_katalog(): void
    {
        $mitra = $this->user(User::ROLE_DISTRIBUTOR);
        $this->actingAs($mitra)->get(route('join-packages.index'))->assertForbidden();
    }

    /**
     * Render smoke test: index + create form + edit form (with existing items) must
     * return 200. Guards against Blade compile-500 from inline JS in form.blade.php
     * (recurring gotcha in this codebase — see feedback-blade-json-array-literal).
     */
    public function test_admin_bisa_buka_index_dan_form(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $p = $this->product();
        $paket = JoinPackage::create(['name' => 'Gold', 'target_role' => User::ROLE_RESELLER_GOLD, 'price' => 459000, 'is_active' => true]);
        $paket->items()->create(['product_id' => $p->id, 'qty' => 5]);

        $this->actingAs($admin)->get(route('join-packages.index'))->assertOk()->assertSee('Gold');
        $this->actingAs($admin)->get(route('join-packages.create'))->assertOk();
        $this->actingAs($admin)->get(route('join-packages.edit', $paket))->assertOk()->assertSee($p->name);
    }

    public function test_admin_update_dan_hapus_paket(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $p1 = $this->product();
        $p2 = $this->product();

        $paket = JoinPackage::create(['name' => 'Bronze', 'target_role' => User::ROLE_RESELLER_BRONZE, 'price' => 149000, 'is_active' => true]);
        $oldItem = $paket->items()->create(['product_id' => $p1->id, 'qty' => 2]);

        $this->actingAs($admin)->put(route('join-packages.update', $paket), [
            'name' => 'Bronze Plus', 'target_role' => User::ROLE_RESELLER_BRONZE, 'price' => 179000, 'is_active' => 1,
            'items' => [['product_id' => $p2->id, 'qty' => 5]],
        ])->assertRedirect();

        $paket->refresh();
        $this->assertSame('Bronze Plus', $paket->name);
        $this->assertCount(1, $paket->items);
        $this->assertSame($p2->id, $paket->items->first()->product_id);
        $this->assertSame(5, $paket->items->first()->qty);
        $this->assertDatabaseMissing('join_package_items', ['id' => $oldItem->id]);

        $this->actingAs($admin)->delete(route('join-packages.destroy', $paket))->assertRedirect();

        $this->assertDatabaseMissing('join_packages', ['id' => $paket->id]);
        $this->assertDatabaseMissing('join_package_items', ['join_package_id' => $paket->id]);
    }
}
