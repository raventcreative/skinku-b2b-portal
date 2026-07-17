<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u, string $status = User::STATUS_ACTIVE): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'company_name' => strtoupper($u).' MART',
            'role' => $role, 'status' => $status,
        ]);
    }

    public function test_super_admin_bisa_masuk_sebagai_mitra_dan_kembali(): void
    {
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'freddie');
        $mitra = $this->user(User::ROLE_DISTRIBUTOR, 'armuznah');

        $this->actingAs($sa)->post("/users/{$mitra->id}/impersonate")->assertRedirect(route('dashboard'));

        $this->assertSame($mitra->id, auth()->id());
        $this->assertSame($sa->id, session(ImpersonationService::SESSION_KEY));

        // Berhenti dipanggil SEBAGAI MITRA — yang tak punya manage_users. Rute
        // stop tak boleh terkunci di balik peran, kalau tidak admin terjebak.
        $this->post('/impersonate/stop')->assertRedirect(route('users.index'));

        $this->assertSame($sa->id, auth()->id());
        $this->assertNull(session(ImpersonationService::SESSION_KEY));
    }

    public function test_tercatat_di_audit_log_dengan_dua_nama(): void
    {
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'freddie');
        $mitra = $this->user(User::ROLE_DISTRIBUTOR, 'armuznah');

        $this->actingAs($sa)->post("/users/{$mitra->id}/impersonate");

        $mulai = AuditLog::where('action', 'impersonate_start')->first();
        $this->assertNotNull($mulai);
        $this->assertSame($sa->id, (int) $mulai->performed_by);      // pelakunya super admin...
        $this->assertSame($mitra->id, (int) $mulai->target_user_id); // ...sasarannya mitra

        $this->post('/impersonate/stop');

        $selesai = AuditLog::where('action', 'impersonate_stop')->first();
        $this->assertNotNull($selesai);
        $this->assertSame($mitra->id, (int) $selesai->target_user_id);
    }

    public function test_admin_biasa_tidak_boleh_menyamar(): void
    {
        $admin = $this->user(User::ROLE_ADMIN, 'adminbiasa');
        $mitra = $this->user(User::ROLE_DISTRIBUTOR, 'armuznah');

        $this->actingAs($admin)->post("/users/{$mitra->id}/impersonate")->assertForbidden();
        $this->assertSame($admin->id, auth()->id());
    }

    public function test_mitra_tidak_boleh_menyamar_siapa_pun(): void
    {
        $a = $this->user(User::ROLE_DISTRIBUTOR, 'mitraa');
        $b = $this->user(User::ROLE_RESELLER, 'mitrab');

        $this->actingAs($a)->post("/users/{$b->id}/impersonate")->assertForbidden();
        $this->assertSame($a->id, auth()->id());
    }

    public function test_super_admin_tidak_bisa_disamari(): void
    {
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'freddie');
        $sa2 = $this->user(User::ROLE_SUPER_ADMIN, 'sasaudara');

        $this->actingAs($sa)->from(route('users.index'))->post("/users/{$sa2->id}/impersonate")
            ->assertRedirect(route('users.index'))
            ->assertSessionHasErrors('impersonate');

        $this->assertSame($sa->id, auth()->id());
    }

    public function test_akun_nonaktif_tidak_bisa_disamari(): void
    {
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'freddie');
        $mati = $this->user(User::ROLE_RESELLER, 'nonaktif', User::STATUS_INACTIVE);

        $this->actingAs($sa)->from(route('users.index'))->post("/users/{$mati->id}/impersonate")
            ->assertSessionHasErrors('impersonate');

        $this->assertSame($sa->id, auth()->id());
    }

    /**
     * Menyamar berantai akan menimpa id asli di sesi: A→B lalu B→C membuat
     * "kembali" berhenti di B, dan super admin tak pernah pulang ke A.
     */
    public function test_tidak_bisa_menyamar_berantai(): void
    {
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'freddie');
        $b = $this->user(User::ROLE_DISTRIBUTOR, 'mitrab');
        $c = $this->user(User::ROLE_RESELLER, 'mitrac');

        $this->actingAs($sa)->post("/users/{$b->id}/impersonate");
        $this->from(route('dashboard'))->post("/users/{$c->id}/impersonate");

        // Masih sebagai B, dan jalan pulang ke super admin tetap utuh.
        $this->assertSame($b->id, auth()->id());
        $this->assertSame($sa->id, session(ImpersonationService::SESSION_KEY));
    }

    public function test_stop_tanpa_sedang_menyamar_ditolak(): void
    {
        $mitra = $this->user(User::ROLE_DISTRIBUTOR, 'armuznah');

        $this->actingAs($mitra)->post('/impersonate/stop')->assertSessionHasErrors('impersonate');
        $this->assertSame($mitra->id, auth()->id());
    }

    /**
     * Kunci sesi palsu tak boleh jadi jalan naik pangkat: mitra yang menyuntik
     * impersonator_id ke sesinya sendiri akan "kembali" menjadi super admin.
     * Sesi Laravel ditandatangani jadi ini mustahil dari luar — tapi kalau suatu
     * saat ada kode lain yang menulis kunci itu, test ini yang menangkapnya.
     */
    public function test_stop_tetap_menuntut_id_sesi_menunjuk_super_admin_aktif(): void
    {
        $mitra = $this->user(User::ROLE_DISTRIBUTOR, 'armuznah');
        $korban = $this->user(User::ROLE_ADMIN, 'adminbiasa');

        $this->actingAs($mitra)
            ->withSession([ImpersonationService::SESSION_KEY => $korban->id])
            ->post('/impersonate/stop');

        // Target bukan super admin → sesi diputus total, bukan malah naik pangkat.
        $this->assertGuest();
    }

    public function test_banner_muncul_di_halaman_biasa_saat_menyamar_dan_hilang_setelah_kembali(): void
    {
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'freddie');
        $mitra = $this->user(User::ROLE_DISTRIBUTOR, 'armuznah');

        // Sebelum menyamar: tak ada banner.
        $this->actingAs($sa)->get('/dashboard')->assertOk()->assertDontSee('sedang masuk sebagai');

        $this->post("/users/{$mitra->id}/impersonate");

        // Banner ikut ke halaman mana pun, bukan cuma dashboard.
        foreach (['/dashboard', '/purchase-orders'] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('sedang masuk sebagai')
                ->assertSee('ARMUZNAH')            // sedang jadi siapa
                ->assertSee('FREDDIE');            // jalan pulang ke siapa
        }

        $this->post('/impersonate/stop');
        $this->get('/dashboard')->assertOk()->assertDontSee('sedang masuk sebagai');
    }

    public function test_tidak_bisa_ganti_password_saat_menyamar(): void
    {
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'freddie');
        $mitra = $this->user(User::ROLE_DISTRIBUTOR, 'armuznah');
        $sebelum = $mitra->password;

        $this->actingAs($sa)->post("/users/{$mitra->id}/impersonate");

        $this->from(route('account.password'))->post('/account/password', [
            'current_password' => 'secret123',
            'password' => 'passwordbaru123',
            'password_confirmation' => 'passwordbaru123',
        ])->assertSessionHasErrors('password');

        // Password mitra tak berubah — pemilik aslinya tak terkunci di luar.
        $this->assertSame($sebelum, $mitra->fresh()->password);
    }
}
