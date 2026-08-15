<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Commission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CommissionModelTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $role = User::ROLE_DISTRIBUTOR): User
    {
        $u = 'u'.(++$this->seq);

        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'company_name' => 'CV '.$u, 'role' => $role,
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_commission_tersimpan_dan_appsetting_float(): void
    {
        $u = $this->user();
        $c = Commission::create([
            'user_id' => $u->id, 'source_po_id' => null, 'source_user_id' => $u->id,
            'type' => 'override', 'level' => 1, 'rate' => 6.0, 'base_amount' => 100000,
            'amount' => 6000, 'status' => 'saldo',
        ]);
        $this->assertSame(6000.0, (float) $c->fresh()->amount);
        AppSetting::put('komisi_persen_grand_distributor', '6');
        $this->assertSame(6.0, AppSetting::float('komisi_persen_grand_distributor', 0));
        $this->assertSame(4.5, AppSetting::float('tidak_ada', 4.5)); // default
    }
}
