<?php

namespace Tests\Feature;

use App\Models\PartnerSale;
use App\Models\User;
use App\Services\NetworkSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Test langsung terhadap NetworkSummaryService (tanpa HTTP), memverifikasi
 * angka-angka agregasi secara presisi — melengkapi JaringanSayaTest yang hanya
 * mengecek string di halaman.
 */
class NetworkSummaryServiceTest extends TestCase
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

    private function service(): NetworkSummaryService
    {
        return app(NetworkSummaryService::class);
    }

    public function test_omzet_dan_transaksi_bulan_ini_benar(): void
    {
        Carbon::setTestNow('2026-08-11 09:00:00');
        $grand = $this->mk('grand', User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->mk('distri', User::ROLE_DISTRIBUTOR, $grand->id);
        $this->sale($dist, '2026-08-05', 170_000);
        $this->sale($dist, '2026-08-08', 30_000);
        $this->sale($dist, '2026-06-01', 999_000); // bulan lain, tak dihitung omzet/trx bulan ini

        $payload = $this->service()->summarize($grand);
        $node = $payload['tree'][0];

        $this->assertSame(200_000.0, $node['omzet']);
        $this->assertSame(2, $node['trx']);
        $this->assertSame(200_000.0, $payload['networkOmzet']);
    }

    public function test_tren_3_bulan_dan_arah_benar(): void
    {
        Carbon::setTestNow('2026-08-11 09:00:00');
        $grand = $this->mk('grand', User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->mk('distri', User::ROLE_DISTRIBUTOR, $grand->id);
        $this->sale($dist, '2026-06-10', 11_000);
        $this->sale($dist, '2026-07-10', 22_000);
        $this->sale($dist, '2026-08-10', 33_000);

        $payload = $this->service()->summarize($grand);
        $node = $payload['tree'][0];

        $this->assertSame(['Jun', 'Jul', 'Agu'], $payload['trenLabels']);
        $this->assertSame([11_000.0, 22_000.0, 33_000.0], $node['tren']);
        $this->assertSame('naik', $node['tren_arah']);
    }

    public function test_status_aktif_30_hari_vs_pasif(): void
    {
        Carbon::setTestNow('2026-08-11 09:00:00');
        $grand = $this->mk('grand', User::ROLE_GRAND_DISTRIBUTOR);
        $aktif = $this->mk('rajin', User::ROLE_DISTRIBUTOR, $grand->id);
        $pasif = $this->mk('malas', User::ROLE_DISTRIBUTOR, $grand->id);
        $this->sale($aktif, '2026-08-06', 50_000); // 5 hari lalu → aktif
        $this->sale($pasif, '2026-06-20', 50_000); // >30 hari → pasif

        $payload = $this->service()->summarize($grand);
        $byId = collect($payload['tree'])->keyBy('id');

        $this->assertTrue($byId[$aktif->id]['aktif']);
        $this->assertFalse($byId[$pasif->id]['aktif']);
        $this->assertSame(1, $payload['activeCount']);
    }

    public function test_isolasi_hanya_subtree_root(): void
    {
        $grand = $this->mk('grand', User::ROLE_GRAND_DISTRIBUTOR);
        $distA = $this->mk('dista', User::ROLE_DISTRIBUTOR, $grand->id);
        $distB = $this->mk('distb', User::ROLE_DISTRIBUTOR, $grand->id);
        $this->mk('resela', User::ROLE_RESELLER_BRONZE, $distA->id);
        $this->mk('reselb', User::ROLE_RESELLER_BRONZE, $distB->id);

        $payload = $this->service()->summarize($distA);

        $this->assertSame(1, $payload['totalMembers']);
        $this->assertSame(['RESELA'], collect($payload['tree'])->pluck('name')->all());
    }

    public function test_leaf_menghasilkan_tree_kosong(): void
    {
        $reseller = $this->mk('sendiri', User::ROLE_RESELLER_BRONZE);

        $payload = $this->service()->summarize($reseller);

        $this->assertSame([], $payload['tree']);
        $this->assertSame(0, $payload['totalMembers']);
        $this->assertSame(0, $payload['activeCount']);
        $this->assertSame(0.0, $payload['networkOmzet']);
    }
}
