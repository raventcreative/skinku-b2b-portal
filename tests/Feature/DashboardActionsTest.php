<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DashboardActionsTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $role): User
    {
        $u = 'u'.(++$this->seq);

        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'company_name' => 'CV '.$u, 'role' => $role,
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_grup_laporan_tampil_di_nav(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertSee('grpLaporan')        // accordion group "Laporan" render
            ->assertSee('Laporan Komisi');   // anak grup (admin punya view_commission_report)
    }

    public function test_dashboard_tampil_panel_penarikan_menunggu(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $mitra = $this->user(User::ROLE_DISTRIBUTOR);
        Withdrawal::create([
            'user_id' => $mitra->id, 'amount' => 500000, 'status' => 'diajukan', 'requested_at' => now(),
        ]);

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertSee('Perlu Tindakan')
            ->assertSee('500.000')           // jumlah penarikan tampil
            ->assertSee($mitra->fullname);   // nama mitra tampil
    }

    public function test_panel_tak_tampil_kalau_tak_ada_penarikan(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Perlu Tindakan');
    }

    public function test_penarikan_cair_tak_muncul_di_panel(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $mitra = $this->user(User::ROLE_DISTRIBUTOR);
        // Hanya status 'diajukan' yang menunggu; 'cair' sudah selesai → tak tampil.
        Withdrawal::create(['user_id' => $mitra->id, 'amount' => 777000, 'status' => 'cair', 'requested_at' => now()]);

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Perlu Tindakan')
            ->assertDontSee('777.000');
    }
}
