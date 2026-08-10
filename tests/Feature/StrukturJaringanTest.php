<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StrukturJaringanTest extends TestCase
{
    use RefreshDatabase;

    private function mk(string $u, string $role, ?int $upline = null): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE, 'upline_id' => $upline,
            'member_id' => strtoupper($u).'-ID',
        ]);
    }

    public function test_render_pohon_dan_panel_belum_ditempatkan(): void
    {
        $sa = User::create([
            'name' => 'sa', 'fullname' => 'SA', 'username' => 'sa', 'email' => 'sa@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
        $grand = $this->mk('grandx', 'grand_distributor');
        $this->mk('distx', 'distributor', $grand->id);
        $this->mk('lepas', 'distributor'); // belum ditempatkan

        $res = $this->actingAs($sa)->get(route('struktur-jaringan.index'))->assertOk();
        $res->assertSee('GRANDX');   // root
        $res->assertSee('DISTX');    // child
        $res->assertSee('Belum ditempatkan');
        $res->assertSee('LEPAS');
    }

    public function test_mitra_tidak_boleh_akses(): void
    {
        $dist = $this->mk('d', 'distributor');
        $this->actingAs($dist)->get(route('struktur-jaringan.index'))->assertForbidden();
    }
}
