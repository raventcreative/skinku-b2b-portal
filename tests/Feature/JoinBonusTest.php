<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Commission;
use App\Models\User;
use App\Services\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Bonus join 10% ke PEREKRUT (member->sponsor), BUKAN upline pasok. Tanpa perekrut
 * (daftar mandiri, sponsor_id null) → tak ada bonus (HQ simpan penuh).
 */
class JoinBonusTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $role, array $extra = []): User
    {
        $u = 'u'.(++$this->seq);

        return User::create(array_merge([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@t.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ], $extra));
    }

    public function test_bonus_join_10_persen_ke_sponsor(): void
    {
        $sponsor = $this->user(User::ROLE_DISTRIBUTOR);
        $member = $this->user(User::ROLE_RESELLER_BRONZE, ['sponsor_id' => $sponsor->id]);

        app(CommissionService::class)->recordJoinBonus($member, 149000);

        $row = Commission::where('user_id', $sponsor->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('join', $row->type);
        $this->assertSame('saldo', $row->status);
        $this->assertSame($member->id, $row->source_user_id);
        $this->assertNull($row->source_po_id);
        $this->assertEqualsWithDelta(14900, (float) $row->amount, 0.01); // 10% dari 149rb
    }

    public function test_tanpa_sponsor_nol_bonus(): void
    {
        $member = $this->user(User::ROLE_RESELLER_BRONZE); // sponsor_id null → daftar mandiri

        app(CommissionService::class)->recordJoinBonus($member, 149000);

        $this->assertSame(0, Commission::count());
    }

    public function test_sponsor_bukan_partner_nol_bonus(): void
    {
        $admin = $this->user(User::ROLE_ADMIN); // bukan partner
        $member = $this->user(User::ROLE_RESELLER_BRONZE, ['sponsor_id' => $admin->id]);

        app(CommissionService::class)->recordJoinBonus($member, 149000);

        $this->assertSame(0, Commission::count());
    }

    public function test_rate_join_dari_appsetting(): void
    {
        AppSetting::put('komisi_persen_join', '5');
        $sponsor = $this->user(User::ROLE_DISTRIBUTOR);
        $member = $this->user(User::ROLE_RESELLER_BRONZE, ['sponsor_id' => $sponsor->id]);

        app(CommissionService::class)->recordJoinBonus($member, 200000);

        $this->assertEqualsWithDelta(10000, (float) Commission::where('user_id', $sponsor->id)->value('amount'), 0.01); // 5%
    }
}
