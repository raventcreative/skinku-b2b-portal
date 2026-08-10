<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StrukturDragDropTest extends TestCase
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

    public function test_place_set_upline_update_master_dan_member_id(): void
    {
        $sa = $this->sa();
        $grand = $this->mk('grand', 'grand_distributor');
        $dist = $this->mk('dist', 'distributor');

        $this->actingAs($sa)->postJson(route('struktur-jaringan.place', $dist), ['upline_id' => $grand->id])
            ->assertOk()->assertJson(['ok' => true]);

        $dist->refresh();
        $this->assertSame($grand->id, $dist->upline_id);   // master berubah (kelihatan di Kelola Anggota)
        $this->assertNotNull($dist->member_id);            // Member ID otomatis
    }

    public function test_place_tolak_level_salah(): void
    {
        $sa = $this->sa();
        $grand = $this->mk('grand', 'grand_distributor');
        $reseller = $this->mk('res', 'reseller_bronze');

        // reseller induknya harus distributor, bukan grand → 422, master tak berubah
        $this->actingAs($sa)->postJson(route('struktur-jaringan.place', $reseller), ['upline_id' => $grand->id])
            ->assertStatus(422)->assertJson(['ok' => false]);

        $this->assertNull($reseller->fresh()->upline_id);
    }

    public function test_lepas_upline_null(): void
    {
        $sa = $this->sa();
        $grand = $this->mk('grand', 'grand_distributor');
        $dist = $this->mk('dist', 'distributor', $grand->id);

        $this->actingAs($sa)->postJson(route('struktur-jaringan.place', $dist), ['upline_id' => null])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertNull($dist->fresh()->upline_id);
    }

    public function test_mitra_tak_boleh_place(): void
    {
        $dist = $this->mk('d', 'distributor');
        $target = $this->mk('g', 'grand_distributor');
        $this->actingAs($dist)->postJson(route('struktur-jaringan.place', $target), ['upline_id' => null])
            ->assertForbidden();
    }
}
