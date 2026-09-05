<?php

namespace Tests\Feature;

use App\Models\MemberDormancyRule;
use App\Models\User;
use App\Services\MemberDormancyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MemberDormancyTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role, string $u, array $attrs = []): User
    {
        $user = User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
        // forceFill: supaya bisa set kolom yang tak fillable (created_at, disabled_at)
        // + last_login_at/sponsor_id/status untuk skenario tes. save() saat update TIDAK
        // menimpa created_at yang sudah kita set.
        if ($attrs !== []) {
            $user->forceFill($attrs)->save();
        }

        return $user;
    }

    public function test_migrasi_seed_6_aturan_default_nonaktif(): void
    {
        $this->assertSame(6, MemberDormancyRule::count());
        $grand = MemberDormancyRule::where('role', 'grand_distributor')->first();
        $this->assertNotNull($grand);
        $this->assertSame('order', $grand->basis);
        $this->assertSame(6, $grand->inactive_months);
        $this->assertFalse($grand->enabled);

        $sponsor = MemberDormancyRule::where('role', 'sponsor')->first();
        $this->assertSame('login', $sponsor->basis);
        $this->assertSame(3, $sponsor->inactive_months);
    }

    public function test_login_asli_menstempel_last_login_at(): void
    {
        $u = $this->member(User::ROLE_RESELLER, 'lg1');
        $this->assertNull($u->last_login_at);

        $this->post('/login', ['login' => 'lg1', 'password' => 'secret123'])->assertRedirect();

        $this->assertNotNull($u->fresh()->last_login_at);
    }

    public function test_impersonasi_tidak_menstempel_last_login(): void
    {
        $admin = $this->member(User::ROLE_SUPER_ADMIN, 'sa1');
        $target = $this->member(User::ROLE_DISTRIBUTOR, 'tg1');

        $this->actingAs($admin)->post(route('users.impersonate', $target))->assertRedirect();

        $this->assertNull($target->fresh()->last_login_at);
    }

    private function rule(string $role, string $basis, int $months, ?Carbon $activatedAt = null): MemberDormancyRule
    {
        return MemberDormancyRule::updateOrCreate(['role' => $role], [
            'enabled' => true, 'basis' => $basis, 'inactive_months' => $months, 'activated_at' => $activatedAt,
        ]);
    }

    public function test_last_activity_per_basis(): void
    {
        $svc = app(MemberDormancyService::class);

        // login
        $u = $this->member(User::ROLE_RESELLER, 'b1', ['last_login_at' => Carbon::parse('2026-01-10')]);
        $this->assertSame('2026-01-10', $svc->lastActivityDate($u, 'login')->toDateString());

        // order (PO non-cancelled terbaru) — DB::table insert supaya bisa set created_at
        // langsung & lepas dari daftar fillable PurchaseOrder.
        $d = $this->member(User::ROLE_DISTRIBUTOR, 'b2');
        DB::table('purchase_orders')->insert([
            ['user_id' => $d->id, 'created_by' => $d->id, 'po_number' => 'PO-1', 'status' => 'completed', 'total_amount' => 0, 'created_at' => '2026-02-01 00:00:00', 'updated_at' => now()],
            ['user_id' => $d->id, 'created_by' => $d->id, 'po_number' => 'PO-2', 'status' => 'cancelled', 'total_amount' => 0, 'created_at' => '2026-03-01 00:00:00', 'updated_at' => now()],
        ]);
        $this->assertSame('2026-02-01', $svc->lastActivityDate($d, 'order')->toDateString()); // cancelled diabaikan

        // recruit (downline/rekrut terbaru)
        $s = $this->member(User::ROLE_SPONSOR, 'b3');
        $this->member(User::ROLE_RESELLER, 'b3a', ['sponsor_id' => $s->id, 'created_at' => Carbon::parse('2026-02-20')]);
        $this->assertSame('2026-02-20', $svc->lastActivityDate($s, 'recruit')->toDateString());
    }

    public function test_effective_date_lindungi_masa_tenggang_dan_member_baru(): void
    {
        $svc = app(MemberDormancyService::class);
        // Aturan baru dinyalakan hari ini; member lama tanpa aktivitas → efektif = activated_at (bukan beku).
        $rule = $this->rule(User::ROLE_RESELLER, 'login', 3, now());
        $old = $this->member(User::ROLE_RESELLER, 'old1', ['created_at' => Carbon::parse('2024-01-01')]);
        $this->assertFalse($svc->isDormant($old, $rule, now()));
    }

    public function test_is_dormant_di_batas(): void
    {
        $svc = app(MemberDormancyService::class);
        $rule = $this->rule(User::ROLE_RESELLER, 'login', 3, Carbon::parse('2020-01-01'));
        $now = Carbon::parse('2026-06-01');

        $aktif = $this->member(User::ROLE_RESELLER, 'a1', ['created_at' => Carbon::parse('2020-01-01'), 'last_login_at' => Carbon::parse('2026-04-01')]);
        $this->assertFalse($svc->isDormant($aktif, $rule, $now)); // 2 bln lalu < 3 bln

        $dorman = $this->member(User::ROLE_RESELLER, 'd1', ['created_at' => Carbon::parse('2020-01-01'), 'last_login_at' => Carbon::parse('2026-01-01')]);
        $this->assertTrue($svc->isDormant($dorman, $rule, $now)); // 5 bln lalu > 3 bln
        $this->assertSame(0, $svc->atRiskDays($dorman, $rule, $now));
    }

    public function test_command_bekukan_dorman_lewati_aktif_dan_staff(): void
    {
        $this->rule(User::ROLE_RESELLER, 'login', 3, Carbon::parse('2020-01-01'));

        $dorman = $this->member(User::ROLE_RESELLER, 'cf1', ['created_at' => Carbon::parse('2020-01-01'), 'last_login_at' => now()->subMonths(5)]);
        $aktif = $this->member(User::ROLE_RESELLER, 'cf2', ['created_at' => Carbon::parse('2020-01-01'), 'last_login_at' => now()->subDays(3)]);
        // Admin punya last_login lama TAPI tak boleh kena (staff + tak ada rule role admin).
        $admin = $this->member(User::ROLE_ADMIN, 'cf3', ['last_login_at' => now()->subYears(2)]);

        $this->artisan('members:auto-freeze')->assertSuccessful();

        $this->assertSame(User::STATUS_INACTIVE, $dorman->fresh()->status);
        $this->assertNotNull($dorman->fresh()->disabled_at);
        $this->assertSame(User::STATUS_ACTIVE, $aktif->fresh()->status);
        $this->assertSame(User::STATUS_ACTIVE, $admin->fresh()->status);
    }

    public function test_command_hormati_enabled_dan_dry_run(): void
    {
        // Aturan NONAKTIF → tak boleh ada yang dibekukan.
        MemberDormancyRule::updateOrCreate(['role' => User::ROLE_RESELLER], ['enabled' => false, 'basis' => 'login', 'inactive_months' => 3, 'activated_at' => Carbon::parse('2020-01-01')]);
        $u = $this->member(User::ROLE_RESELLER, 'df1', ['created_at' => Carbon::parse('2020-01-01'), 'last_login_at' => now()->subMonths(9)]);
        $this->artisan('members:auto-freeze')->assertSuccessful();
        $this->assertSame(User::STATUS_ACTIVE, $u->fresh()->status);

        // Aktifkan + dry-run → tetap tak berubah.
        $this->rule(User::ROLE_RESELLER, 'login', 3, Carbon::parse('2020-01-01'));
        $this->artisan('members:auto-freeze', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(User::STATUS_ACTIVE, $u->fresh()->status);
    }

    public function test_member_beku_tak_bisa_login(): void
    {
        // Rangkaian: dorman → command bekukan → login ditolak (mekanisme AuthController).
        $this->rule(User::ROLE_RESELLER, 'login', 3, Carbon::parse('2020-01-01'));
        $u = $this->member(User::ROLE_RESELLER, 'fl1', ['created_at' => Carbon::parse('2020-01-01'), 'last_login_at' => now()->subMonths(9)]);

        $this->artisan('members:auto-freeze')->assertSuccessful();
        $this->assertSame(User::STATUS_INACTIVE, $u->fresh()->status);

        $this->post('/login', ['login' => 'fl1', 'password' => 'secret123'])
            ->assertSessionHasErrors('login'); // ditolak karena status != active
        $this->assertGuest();
    }

    public function test_gate_izin_dan_render(): void
    {
        // reseller (mitra) tak punya izin → 403.
        $this->actingAs($this->member(User::ROLE_RESELLER, 'g1'))->get(route('member-dormancy.index'))->assertForbidden();
        // Task 6 menambah assertion admin bisa buka halaman (butuh view).
    }

    public function test_save_rules_set_activated_at_saat_dinyalakan(): void
    {
        $admin = $this->member(User::ROLE_ADMIN, 'sr1');
        $this->actingAs($admin)->post(route('member-dormancy.rules'), [
            'rules' => [
                'grand_distributor' => ['enabled' => '1', 'inactive_months' => 6, 'basis' => 'order'],
                'distributor' => ['inactive_months' => 3, 'basis' => 'order'], // enabled tak dicentang
                'reseller' => ['inactive_months' => 3, 'basis' => 'login'],
                'reseller_bronze' => ['inactive_months' => 3, 'basis' => 'login'],
                'reseller_gold' => ['inactive_months' => 3, 'basis' => 'login'],
                'sponsor' => ['inactive_months' => 3, 'basis' => 'login'],
            ],
        ])->assertRedirect();

        $grand = MemberDormancyRule::where('role', 'grand_distributor')->first();
        $this->assertTrue($grand->enabled);
        $this->assertNotNull($grand->activated_at); // masa tenggang mulai
        $this->assertFalse(MemberDormancyRule::where('role', 'distributor')->first()->enabled);
    }

    public function test_reactivate_balikin_aktif(): void
    {
        $admin = $this->member(User::ROLE_ADMIN, 'ra1');
        $beku = $this->member(User::ROLE_RESELLER, 'ra2', ['status' => User::STATUS_INACTIVE, 'disabled_at' => now()]);

        $this->actingAs($admin)->post(route('member-dormancy.reactivate', $beku))->assertRedirect();

        $this->assertSame(User::STATUS_ACTIVE, $beku->fresh()->status);
        $this->assertNull($beku->fresh()->disabled_at);
    }
}
