<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ShopeeWiringTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => 'u', 'fullname' => 'U', 'username' => 'u'.uniqid(),
            'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'),
            'role' => $role, 'status' => User::STATUS_ACTIVE]);
    }

    public function test_admin_bisa_buka_shopee_index(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN))->get(route('shopee.index'))
            ->assertOk()->assertSee('Integrasi Shopee');
    }

    public function test_mitra_tak_boleh_akses(): void
    {
        $this->actingAs($this->user(User::ROLE_DISTRIBUTOR))->get('/shopee')->assertForbidden();
    }
}
