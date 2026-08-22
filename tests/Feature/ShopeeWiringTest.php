<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ShopeeClient;
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

    public function test_callback_menyimpan_koneksi(): void
    {
        // fake ShopeeClient: getToken balikin token kaleng
        $fake = new class extends ShopeeClient
        {
            public function __construct() {}

            public function configured(): bool
            {
                return true;
            }

            public function getToken(string $code, string $shopId): array
            {
                return ['access_token' => 'A', 'refresh_token' => 'R', 'expire_in' => 14400];
            }
        };
        $this->app->instance(ShopeeClient::class, $fake);

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->get(route('shopee.callback', ['code' => 'xyz', 'shop_id' => '777']))
            ->assertRedirect(route('shopee.index'));

        $this->assertDatabaseHas('shopee_connections', ['shop_id' => '777', 'access_token' => 'A']);
    }
}
