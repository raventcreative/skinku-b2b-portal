<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class JaringanSayaNavTest extends TestCase
{
    use RefreshDatabase;

    private function mk(string $name, string $role, ?int $upline = null): User
    {
        return User::create([
            'name' => $name, 'fullname' => strtoupper($name), 'username' => $name,
            'email' => "{$name}@skinku.test", 'password' => Hash::make('secret123'),
            'role' => $role, 'status' => User::STATUS_ACTIVE, 'upline_id' => $upline,
        ]);
    }

    public function test_menu_muncul_untuk_mitra_dengan_downline(): void
    {
        $grand = $this->mk('grand', User::ROLE_GRAND_DISTRIBUTOR);
        $this->mk('distri', User::ROLE_DISTRIBUTOR, $grand->id);

        $this->actingAs($grand)->get(route('dashboard'))->assertOk()->assertSee('Jaringan Saya');
    }

    public function test_menu_tak_muncul_untuk_mitra_tanpa_downline(): void
    {
        $reseller = $this->mk('solo', User::ROLE_RESELLER_BRONZE);

        $this->actingAs($reseller)->get(route('dashboard'))->assertOk()->assertDontSee('Jaringan Saya');
    }

    public function test_menu_tak_muncul_untuk_non_partner(): void
    {
        $admin = $this->mk('admin', User::ROLE_SUPER_ADMIN);

        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertDontSee('Jaringan Saya');
    }
}
