<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomRoleTest extends TestCase
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

    public function test_system_roles_are_seeded(): void
    {
        foreach (['super_admin', 'admin', 'gudang', 'distributor', 'reseller'] as $r) {
            $this->assertDatabaseHas('roles', ['name' => $r, 'is_system' => true]);
        }
    }

    public function test_super_admin_creates_custom_role(): void
    {
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN))
            ->post('/roles', ['label' => 'Affiliator'])
            ->assertRedirect();

        $this->assertDatabaseHas('roles', ['name' => 'affiliator', 'label' => 'Affiliator', 'is_system' => false]);
    }

    public function test_admin_cannot_reach_role_management(): void
    {
        // manage_permissions is super_admin-only by default
        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->post('/roles', ['label' => 'Hacker'])
            ->assertForbidden();
    }

    public function test_system_role_cannot_be_deleted(): void
    {
        $admin = $this->user(User::ROLE_SUPER_ADMIN);
        $sys = Role::where('name', 'admin')->first();

        $this->actingAs($admin)->delete('/roles/'.$sys->id)->assertSessionHasErrors('role');
        $this->assertDatabaseHas('roles', ['name' => 'admin']);
    }

    public function test_custom_role_permissions_and_access(): void
    {
        Role::create(['name' => 'affiliator', 'label' => 'Affiliator', 'is_system' => false, 'sort_order' => 10]);
        Permissions::flushCache();

        // grant only view_learning to affiliator
        Permissions::save(['affiliator' => ['view_learning' => 'on']]);

        $aff = $this->user('affiliator');
        $this->assertTrue($aff->canDo('view_learning'));
        $this->assertFalse($aff->canDo('view_reports'));
        $this->assertFalse($aff->canDo('manage_products'));

        // can see learning, blocked from products
        Lesson::create(['title' => 'Umum', 'video_url' => 'https://youtu.be/dQw4w9WgXcQ', 'is_published' => true]);
        $this->actingAs($aff)->get('/learning')->assertOk();
        $this->actingAs($aff)->get('/products')->assertForbidden();

        // dashboard renders (minimal) without sales data
        $this->actingAs($aff)->get('/dashboard')->assertOk();
    }
}
