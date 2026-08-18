<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\CommissionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionReportTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /**
     * users.username & users.email wajib unique + NOT NULL (lihat
     * 0001_01_01_000000_create_users_table.php) — tak ada kolom is_active,
     * status pakai User::STATUS_ACTIVE (default kolom 'active').
     */
    private function mitra(string $name, string $role = User::ROLE_DISTRIBUTOR): User
    {
        $n = ++$this->seq;

        return User::create([
            'name' => $name, 'username' => 'mitra'.$n, 'email' => 'mitra'.$n.'@t.test',
            'password' => bcrypt('x'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    /**
     * Commission::$fillable tak punya created_at/updated_at, jadi lewat
     * create() dua kolom itu didiamkan lalu di-auto-stamp "now" oleh Eloquent.
     * Backdate lewat update() query-builder terpisah (pola sama dgn
     * ChannelSalesTest/PoPurgeTest) supaya filter periode benar-benar diuji.
     */
    private function komisi(User $u, float $amount, string $when): void
    {
        $c = Commission::create([
            'user_id' => $u->id, 'source_po_id' => null, 'source_user_id' => null,
            'type' => 'override', 'level' => 1, 'rate' => 4, 'base_amount' => $amount * 25,
            'amount' => $amount, 'status' => 'saldo',
        ]);
        Commission::where('id', $c->id)->update(['created_at' => $when, 'updated_at' => $when]);
    }

    public function test_summary_hitung_saldo_tertahan_cair(): void
    {
        $a = $this->mitra('A');
        $this->komisi($a, 300_000, '2026-08-10 09:00:00');
        $this->komisi($a, 200_000, '2026-07-05 09:00:00'); // bulan lain
        Withdrawal::create(['user_id' => $a->id, 'amount' => 100_000, 'status' => 'diajukan']);
        Withdrawal::create(['user_id' => $a->id, 'amount' => 50_000, 'status' => 'cair']);
        Withdrawal::create(['user_id' => $a->id, 'amount' => 99_000, 'status' => 'ditolak']); // tak dihitung

        $svc = app(CommissionService::class);
        $all = $svc->reportSummary(null);
        $this->assertEqualsWithDelta(500_000, $all['total_saldo'], 0.01);
        $this->assertEqualsWithDelta(150_000, $all['total_tertahan'] + $all['total_cair'], 0.01); // 100k tertahan + 50k cair
        $this->assertEqualsWithDelta(100_000, $all['total_tertahan'], 0.01);
        $this->assertEqualsWithDelta(50_000, $all['total_cair'], 0.01);
        // identitas: saldo = tersedia + tertahan + cair; ditarik(non-ditolak)=150k → tersedia 350k
        $this->assertEqualsWithDelta(350_000, $all['total_tersedia'], 0.01);
        $this->assertSame(1, $all['jumlah_mitra']);

        $agu = $svc->reportSummary(Carbon::create(2026, 8, 1));
        $this->assertEqualsWithDelta(300_000, $agu['komisi_periode'], 0.01); // cuma yg Agustus
        $this->assertEqualsWithDelta(500_000, $agu['total_saldo'], 0.01);     // saldo tetap all-time
    }

    public function test_per_mitra_hanya_yang_pernah_komisi_dan_kolom_benar(): void
    {
        $a = $this->mitra('Andi', User::ROLE_GRAND_DISTRIBUTOR);
        $b = $this->mitra('Budi'); // tak pernah komisi → tak muncul
        $this->komisi($a, 300_000, '2026-08-10 09:00:00');
        Withdrawal::create(['user_id' => $a->id, 'amount' => 100_000, 'status' => 'disetujui']);

        $rows = app(CommissionService::class)->reportPerMitra(null);
        $this->assertCount(1, $rows);
        $this->assertSame($a->id, $rows[0]['user']->id);
        $this->assertSame('Grand Distributor', $rows[0]['tier']);
        $this->assertEqualsWithDelta(300_000, $rows[0]['saldo'], 0.01);
        $this->assertEqualsWithDelta(100_000, $rows[0]['tertahan'], 0.01);
        $this->assertEqualsWithDelta(200_000, $rows[0]['tersedia'], 0.01);
    }

    public function test_mitra_commissions_ikut_periode(): void
    {
        $a = $this->mitra('Andi');
        $this->komisi($a, 300_000, '2026-08-10 09:00:00');
        $this->komisi($a, 200_000, '2026-07-05 09:00:00');

        $svc = app(CommissionService::class);
        $this->assertCount(2, $svc->mitraCommissions($a, null));
        $this->assertCount(1, $svc->mitraCommissions($a, Carbon::create(2026, 8, 1)));
    }
}
