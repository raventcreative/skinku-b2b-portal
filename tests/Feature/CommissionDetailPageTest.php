<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionDetailPageTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /**
     * users.username & users.email wajib unique + NOT NULL, tak ada kolom
     * is_active — status pakai User::STATUS_ACTIVE (pola sama dgn
     * CommissionReportPageTest::admin()/mitra()).
     */
    private function user(string $name, string $role): User
    {
        $n = ++$this->seq;

        return User::create([
            'name' => $name, 'username' => 'cdp'.$n, 'email' => 'cdp'.$n.'@t.test',
            'password' => bcrypt('x'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_admin_lihat_rincian_komisi_mitra(): void
    {
        $admin = $this->user('Adm', User::ROLE_ADMIN);
        $up = $this->user('Upline', User::ROLE_DISTRIBUTOR);
        $down = $this->user('Downline', User::ROLE_RESELLER_BRONZE);
        Commission::create(['user_id' => $up->id, 'source_user_id' => $down->id, 'type' => 'override', 'level' => 1, 'rate' => 4, 'base_amount' => 1_000_000, 'amount' => 40_000, 'status' => 'saldo']);

        $this->actingAs($admin)->get(route('reports.komisi-detail', $up))
            ->assertOk()->assertSee('Upline')->assertSee('Downline')->assertSee('40.000');
    }

    public function test_mitra_ditolak_403(): void
    {
        $x = $this->user('X', User::ROLE_DISTRIBUTOR);
        $this->actingAs($x)->get(route('reports.komisi-detail', $x))->assertForbidden();
    }
}
