<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Commission;
use App\Models\User;
use App\Services\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class JoinBonusTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $role): User
    {
        $u = 'u'.(++$this->seq);

        return User::create(['name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@t.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE]);
    }

    public function test_bonus_join_10_persen_ke_inviter(): void
    {
        $inviter = $this->user(User::ROLE_DISTRIBUTOR);
        $member = $this->user(User::ROLE_RESELLER_BRONZE);

        app(CommissionService::class)->recordJoinBonus($inviter, $member, 149000);

        $row = Commission::where('user_id', $inviter->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('join', $row->type);
        $this->assertSame('saldo', $row->status);
        $this->assertSame($member->id, $row->source_user_id);
        $this->assertNull($row->source_po_id);
        $this->assertEqualsWithDelta(14900, (float) $row->amount, 0.01); // 10% dari 149rb
    }

    public function test_inviter_bukan_partner_nol_bonus(): void
    {
        $admin = $this->user(User::ROLE_ADMIN); // bukan partner
        $member = $this->user(User::ROLE_RESELLER_BRONZE);

        app(CommissionService::class)->recordJoinBonus($admin, $member, 149000);

        $this->assertSame(0, Commission::count());
    }

    public function test_rate_join_dari_appsetting(): void
    {
        AppSetting::put('komisi_persen_join', '5');
        $inviter = $this->user(User::ROLE_DISTRIBUTOR);
        $member = $this->user(User::ROLE_RESELLER_BRONZE);

        app(CommissionService::class)->recordJoinBonus($inviter, $member, 200000);

        $this->assertEqualsWithDelta(10000, (float) Commission::where('user_id', $inviter->id)->value('amount'), 0.01); // 5%
    }
}
