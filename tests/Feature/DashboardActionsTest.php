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

    public function test_grup_menu_baru_tampil_di_nav(): void
    {
        // super_admin: semua izin implisit true, jadi ketiga grup baru pasti render —
        // termasuk Sistem, yang gate-nya (manage_permissions/view_audit_log/dst) DEFAULTS
        // kosong untuk role 'admin' biasa (baru menyala lewat override role_permissions).
        $superAdmin = $this->user(User::ROLE_SUPER_ADMIN);

        $this->actingAs($superAdmin)->get('/dashboard')
            ->assertOk()
            ->assertSee('grpMitra')
            ->assertSee('Mitra & Jaringan')
            ->assertSee('grpKeuangan')
            ->assertSee('Keuangan')
            ->assertSee('grpSistem')
            ->assertSee('Sistem');
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

    public function test_panel_tampil_dengan_empty_state_kalau_tak_ada_penarikan(): void
    {
        // Panel "Perlu Tindakan" sekarang selalu tampil untuk pemroses withdraw —
        // tidak hilang total waktu kosong, tapi menunjukkan empty state.
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertSee('Perlu Tindakan')
            ->assertSee('Belum ada penarikan menunggu')
            ->assertSee('Lihat semua di Penarikan');
    }

    public function test_penarikan_cair_tak_muncul_di_panel(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $mitra = $this->user(User::ROLE_DISTRIBUTOR);
        // Hanya status 'diajukan' yang menunggu; 'cair' sudah selesai → tak masuk
        // daftar. Panel tetap tampil (selalu tampil untuk pemroses withdraw) tapi
        // menunjukkan empty state, bukan baris 777.000.
        Withdrawal::create(['user_id' => $mitra->id, 'amount' => 777000, 'status' => 'cair', 'requested_at' => now()]);

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertSee('Perlu Tindakan')
            ->assertSee('Belum ada penarikan menunggu')
            ->assertDontSee('777.000');
    }

    public function test_panel_tak_tampil_untuk_mitra_tanpa_izin(): void
    {
        // Reseller tak punya izin apa pun yang relevan (process_withdrawal,
        // update_po_status, process_downline_po) → panel "Perlu Tindakan" sama
        // sekali tak tampil, bukan cuma kosong. (Distributor kini JUSTRU tampil
        // karena punya process_downline_po — lihat DashboardActionablePoTest.)
        $mitra = $this->user(User::ROLE_RESELLER);

        $this->actingAs($mitra)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Perlu Tindakan');
    }
}
