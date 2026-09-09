<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SenderSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::create([
            'name' => 'sa', 'fullname' => 'Super', 'username' => 'sa', 'email' => 'sa@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_simpan_setelan_pengirim(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('settings.sender.save'), [
                'hq_sender_name' => 'SKINKU HQ',
                'hq_sender_address' => 'Jl. Mawar 1',
                'hq_sender_city' => 'Surabaya',
                'hq_sender_phone' => '0811222333',
            ])->assertRedirect();

        $this->assertSame('SKINKU HQ', AppSetting::get('hq_sender_name'));
        $this->assertSame('Jl. Mawar 1', AppSetting::get('hq_sender_address'));
        $this->assertSame('Surabaya', AppSetting::get('hq_sender_city'));
        $this->assertSame('0811222333', AppSetting::get('hq_sender_phone'));
    }

    public function test_non_admin_tidak_boleh(): void
    {
        $reseller = User::create([
            'name' => 'r', 'fullname' => 'R', 'username' => 'r', 'email' => 'r@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE,
        ]);

        $this->actingAs($reseller)
            ->post(route('settings.sender.save'), ['hq_sender_name' => 'X'])
            ->assertForbidden();
    }
}
