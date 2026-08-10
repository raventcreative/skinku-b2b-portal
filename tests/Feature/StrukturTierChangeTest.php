<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StrukturTierChangeTest extends TestCase
{
    use RefreshDatabase;

    private function mk(string $u, string $role, ?int $upline = null): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE, 'upline_id' => $upline,
        ]);
    }

    private function sa(): User
    {
        return $this->mk('sa', User::ROLE_SUPER_ADMIN);
    }

    public function test_ubah_tier_reset_upline_bila_tak_valid(): void
    {
        $sa = $this->sa();
        $grand = $this->mk('g', 'grand_distributor');
        $dist = $this->mk('d', 'distributor', $grand->id); // valid: distributor di bawah grand

        // dist -> reseller_bronze: induk sah = distributor, tapi upline-nya grand → di-reset
        $this->actingAs($sa)->postJson(route('struktur-jaringan.tier', $dist), ['role' => 'reseller_bronze'])
            ->assertOk()->assertJson(['ok' => true]);

        $dist->refresh();
        $this->assertSame('reseller_bronze', $dist->role);
        $this->assertNull($dist->upline_id);
    }

    public function test_ubah_tier_pertahankan_upline_valid(): void
    {
        $sa = $this->sa();
        $dist = $this->mk('d', 'distributor');
        $bronze = $this->mk('b', 'reseller_bronze', $dist->id); // valid

        // bronze -> gold: induk sah tetap distributor → upline tetap
        $this->actingAs($sa)->postJson(route('struktur-jaringan.tier', $bronze), ['role' => 'reseller_gold'])
            ->assertOk();

        $bronze->refresh();
        $this->assertSame('reseller_gold', $bronze->role);
        $this->assertSame($dist->id, $bronze->upline_id);
    }

    public function test_ubah_tier_diblok_bila_punya_downline(): void
    {
        $sa = $this->sa();
        $grand = $this->mk('g', 'grand_distributor');
        $this->mk('d', 'distributor', $grand->id); // grand punya downline

        $this->actingAs($sa)->postJson(route('struktur-jaringan.tier', $grand), ['role' => 'distributor'])
            ->assertStatus(422)->assertJson(['ok' => false]);

        $this->assertSame('grand_distributor', $grand->fresh()->role);
    }

    public function test_tolak_ubah_ke_non_tier(): void
    {
        $sa = $this->sa();
        $dist = $this->mk('d', 'distributor');
        $this->actingAs($sa)->postJson(route('struktur-jaringan.tier', $dist), ['role' => 'admin'])
            ->assertStatus(422);
    }
}
