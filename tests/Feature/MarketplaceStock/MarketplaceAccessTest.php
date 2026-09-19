<?php

namespace Tests\Feature\MarketplaceStock;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Task 2: izin manage_marketplace_stock + rute + controller stub + gate.
 * super_admin (implisit/locked) selalu bisa buka; role mitra tanpa izin ditolak.
 */
class MarketplaceAccessTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => 'u', 'fullname' => 'U', 'username' => 'u'.uniqid(),
            'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'),
            'role' => $role, 'status' => User::STATUS_ACTIVE]);
    }

    public function test_super_admin_bisa_buka_role_tanpa_izin_ditolak(): void
    {
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN))
            ->get('/marketplace-stock')->assertOk();

        $this->actingAs($this->user(User::ROLE_RESELLER))
            ->get('/marketplace-stock')->assertForbidden();
    }
}
