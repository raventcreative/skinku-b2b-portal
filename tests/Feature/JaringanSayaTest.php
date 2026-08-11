<?php

namespace Tests\Feature;

use App\Models\PartnerSale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class JaringanSayaTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function mk(string $name, string $role, ?int $upline = null, ?string $region = null): User
    {
        return User::create([
            'name' => $name, 'fullname' => strtoupper($name), 'username' => $name,
            'email' => "{$name}@skinku.test", 'password' => Hash::make('secret123'),
            'role' => $role, 'status' => User::STATUS_ACTIVE, 'upline_id' => $upline, 'region' => $region,
        ]);
    }

    private function sale(User $u, string $soldAt, int $amount, string $customer = 'CUSTOMER RAHASIA'): void
    {
        PartnerSale::create([
            'sale_number' => 'PS-'.(++$this->seq),
            'user_id' => $u->id, 'customer_name' => $customer,
            'total_amount' => $amount, 'sold_at' => $soldAt, 'created_by' => $u->id,
        ]);
    }

    public function test_grand_melihat_seluruh_subtree(): void
    {
        $grand = $this->mk('grand', User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->mk('distri', User::ROLE_DISTRIBUTOR, $grand->id);
        $this->mk('bronzie', User::ROLE_RESELLER_BRONZE, $dist->id);

        $this->actingAs($grand)->get(route('jaringan-saya.index'))
            ->assertOk()->assertSee('DISTRI')->assertSee('BRONZIE');
    }

    public function test_distributor_tak_lihat_jaringan_lain(): void
    {
        $grand = $this->mk('grand', User::ROLE_GRAND_DISTRIBUTOR);
        $distA = $this->mk('dista', User::ROLE_DISTRIBUTOR, $grand->id);
        $distB = $this->mk('distb', User::ROLE_DISTRIBUTOR, $grand->id);
        $this->mk('resela', User::ROLE_RESELLER_BRONZE, $distA->id);
        $this->mk('reselb', User::ROLE_RESELLER_BRONZE, $distB->id);

        $this->actingAs($distA)->get(route('jaringan-saya.index'))
            ->assertOk()->assertSee('RESELA')
            ->assertDontSee('RESELB')->assertDontSee('DISTB');
    }

    public function test_metrik_omzet_dan_transaksi_bulan_ini(): void
    {
        Carbon::setTestNow('2026-08-11 09:00:00');
        $grand = $this->mk('grand', User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->mk('distri', User::ROLE_DISTRIBUTOR, $grand->id);
        $this->sale($dist, '2026-08-05', 170_000);
        $this->sale($dist, '2026-08-08', 30_000);
        $this->sale($dist, '2026-06-01', 999_000); // bulan lain

        $this->actingAs($grand)->get(route('jaringan-saya.index'))
            ->assertOk()->assertSee('200.000')->assertSee('2 transaksi');
    }

    public function test_status_aktif_dan_pasif(): void
    {
        Carbon::setTestNow('2026-08-11 09:00:00');
        $grand = $this->mk('grand', User::ROLE_GRAND_DISTRIBUTOR);
        $aktif = $this->mk('rajin', User::ROLE_DISTRIBUTOR, $grand->id);
        $pasif = $this->mk('malas', User::ROLE_DISTRIBUTOR, $grand->id);
        $this->sale($aktif, '2026-08-06', 50_000);   // 5 hari lalu → aktif
        $this->sale($pasif, '2026-06-20', 50_000);   // >30 hari → pasif

        $res = $this->actingAs($grand)->get(route('jaringan-saya.index'))->assertOk();
        $res->assertSee('Aktif');
        $res->assertSee('Pasif');
    }

    public function test_tren_3_bulan_tampil(): void
    {
        Carbon::setTestNow('2026-08-11 09:00:00');
        $grand = $this->mk('grand', User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->mk('distri', User::ROLE_DISTRIBUTOR, $grand->id);
        $this->sale($dist, '2026-06-10', 11_000);
        $this->sale($dist, '2026-07-10', 22_000);
        $this->sale($dist, '2026-08-10', 33_000);

        $this->actingAs($grand)->get(route('jaringan-saya.index'))->assertOk()
            ->assertSee('Jun')->assertSee('Jul')->assertSee('Agu')
            ->assertSee('11.000')->assertSee('22.000')->assertSee('33.000');
    }

    public function test_reseller_tanpa_downline_lihat_empty_state(): void
    {
        $reseller = $this->mk('sendiri', User::ROLE_RESELLER_BRONZE);

        $this->actingAs($reseller)->get(route('jaringan-saya.index'))
            ->assertOk()->assertSee('belum punya jaringan');
    }

    public function test_non_partner_dilarang(): void
    {
        $admin = $this->mk('admin', User::ROLE_SUPER_ADMIN);

        $this->actingAs($admin)->get(route('jaringan-saya.index'))->assertForbidden();
    }

    public function test_nama_customer_downline_tidak_bocor(): void
    {
        $grand = $this->mk('grand', User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->mk('distri', User::ROLE_DISTRIBUTOR, $grand->id);
        $this->sale($dist, '2026-08-05', 50_000, 'BUDI SANGAT RAHASIA');

        $this->actingAs($grand)->get(route('jaringan-saya.index'))
            ->assertOk()->assertDontSee('BUDI SANGAT RAHASIA');
    }

    public function test_rollup_omzet_jaringan_benar(): void
    {
        Carbon::setTestNow('2026-08-11 09:00:00');
        $grand = $this->mk('grand', User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->mk('distri', User::ROLE_DISTRIBUTOR, $grand->id);
        $resel = $this->mk('resel', User::ROLE_RESELLER_BRONZE, $dist->id);
        $this->sale($dist, '2026-08-05', 100_000);
        $this->sale($resel, '2026-08-06', 25_000);

        $this->actingAs($grand)->get(route('jaringan-saya.index'))->assertOk()
            ->assertSee('125.000');
    }
}
