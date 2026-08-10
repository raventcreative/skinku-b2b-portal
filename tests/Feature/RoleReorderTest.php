<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RoleReorderTest extends TestCase
{
    use RefreshDatabase;

    private function sa(): User
    {
        return User::create([
            'name' => 'sa', 'fullname' => 'SA', 'username' => 'sa', 'email' => 'sa@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_reorder_simpan_sort_order_dan_persist(): void
    {
        $sa = $this->sa();
        // urutan baru: distributor duluan, lalu admin, lalu super_admin
        $order = ['distributor', 'admin', 'super_admin', 'gudang', 'reseller'];

        $this->actingAs($sa)->postJson(route('roles.reorder'), ['order' => $order])
            ->assertOk()->assertJson(['ok' => true]);

        // sort_order mencerminkan posisi
        $this->assertLessThan(Role::where('name', 'admin')->value('sort_order'), Role::where('name', 'distributor')->value('sort_order'));
        $this->assertLessThan(Role::where('name', 'super_admin')->value('sort_order'), Role::where('name', 'admin')->value('sort_order'));

        // Role::ordered() ikut urutan baru (persist — tahan reload)
        $this->assertSame('distributor', Role::ordered()->pluck('name')->first());
    }

    public function test_non_admin_tak_boleh_reorder(): void
    {
        $dist = User::create([
            'name' => 'd', 'fullname' => 'D', 'username' => 'd', 'email' => 'd@skinku.test',
            'password' => Hash::make('secret123'), 'role' => 'distributor', 'status' => User::STATUS_ACTIVE,
        ]);
        $this->actingAs($dist)->postJson(route('roles.reorder'), ['order' => ['admin']])->assertForbidden();
    }
}
