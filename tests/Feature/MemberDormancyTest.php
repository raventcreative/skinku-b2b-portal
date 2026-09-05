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
}
