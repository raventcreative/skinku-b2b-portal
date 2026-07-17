<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\PurchaseOrderService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Filter ?bulan= pada Laporan Penjualan.
 *
 * Test ini ada karena bug nyata: ReportController memakai Carbon tanpa
 * meng-import-nya, sehingga parseMonth() melempar Error yang lalu DITELAN oleh
 * catch(\Throwable) dan selalu balik null. Filternya tak pernah jalan dan
 * halaman diam-diam menampilkan "semua periode" apa pun pilihan pengguna.
 *
 * Test unit di ReportService tak akan menangkapnya — service-nya baik-baik saja.
 * Yang rusak cuma controller. Karena itu test ini menembak ROUTE-nya, bukan
 * service-nya.
 */
class ReportMonthFilterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'HQ', 'fullname' => 'HQ', 'username' => 'rmfadm', 'email' => 'rmf@skinku.test',
            'password' => Hash::make('secret123'),
            'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function partner(): User
    {
        return User::create([
            'name' => 'Erin', 'fullname' => 'Erin', 'username' => 'rmferin', 'email' => 'rmferin@skinku.test',
            'password' => Hash::make('secret123'),
            'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE,
            'company_name' => 'SKINKU BALI',
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'name' => 'Body Soap', 'sku' => 'RMF-1', 'hq_stock' => 1000, 'status' => 'active',
            'cogs' => 10_000, 'price_distributor' => 20_000, 'price_reseller' => 25_000,
        ]);
    }

    /** Juli: 10 pcs (250rb) — Juni: 30 pcs (750rb). Total semua periode = 1jt. */
    private function seedTwoMonths(): void
    {
        $svc = app(PurchaseOrderService::class);
        $p = $this->product();
        $erin = $this->partner();
        $adminId = $this->admin()->id;

        $svc->recordBackdatedSale($erin, [['product_id' => $p->id, 'qty' => 10]], Carbon::parse('2026-07-08'), null, $adminId);
        $svc->recordBackdatedSale($erin, [['product_id' => $p->id, 'qty' => 30]], Carbon::parse('2026-06-08'), null, $adminId);
    }

    public function test_month_filter_changes_the_numbers_on_the_page(): void
    {
        Carbon::setTestNow('2026-07-17 10:00:00');
        $this->seedTwoMonths();
        $admin = User::where('username', 'rmfadm')->first();

        // Tanpa filter = semua periode → 1.000.000
        $this->actingAs($admin)->get('/reports')
            ->assertOk()
            ->assertSee('1.000.000');

        // Filter Juli → HANYA 250.000, dan 1.000.000 tak boleh muncul lagi.
        $this->actingAs($admin)->get('/reports?bulan=2026-07')
            ->assertOk()
            ->assertSee('250.000')
            ->assertDontSee('1.000.000');

        // Filter Juni → 750.000
        $this->actingAs($admin)->get('/reports?bulan=2026-06')
            ->assertOk()
            ->assertSee('750.000');
    }

    public function test_bulan_kosong_atau_ngawur_jatuh_ke_semua_periode(): void
    {
        Carbon::setTestNow('2026-07-17 10:00:00');
        $this->seedTwoMonths();
        $admin = User::where('username', 'rmfadm')->first();

        foreach (['', 'bukan-bulan', '2026-13', '2026-00', '99999-01'] as $v) {
            $this->actingAs($admin)->get('/reports?bulan='.urlencode($v))
                ->assertOk()
                ->assertSee('1.000.000');
        }
    }

    public function test_laba_kotor_dan_hpp_ikut_terfilter(): void
    {
        Carbon::setTestNow('2026-07-17 10:00:00');
        $this->seedTwoMonths();
        $reports = app(ReportService::class);

        $semua = $reports->grossProfit();
        $juli = $reports->grossProfit(Carbon::parse('2026-07-01'));

        // Semua periode: 40 pcs → omzet 1jt, HPP 400rb
        $this->assertEqualsWithDelta(1_000_000, $semua['revenue'], 0.01);
        $this->assertEqualsWithDelta(400_000, $semua['cogs'], 0.01);

        // Juli saja: 10 pcs → omzet 250rb, HPP 100rb. Kartu HPP/laba dulu
        // mengabaikan $bulan sepenuhnya — angkanya tak pernah berubah.
        $this->assertEqualsWithDelta(250_000, $juli['revenue'], 0.01);
        $this->assertEqualsWithDelta(100_000, $juli['cogs'], 0.01);
        $this->assertEqualsWithDelta(150_000, $juli['profit'], 0.01);
    }

    public function test_top_produk_dan_rincian_mitra_ikut_terfilter(): void
    {
        Carbon::setTestNow('2026-07-17 10:00:00');
        $this->seedTwoMonths();
        $reports = app(ReportService::class);

        $this->assertEqualsWithDelta(250_000, $reports->salesByProduct(10, null, Carbon::parse('2026-07-01'))[0]['revenue'], 0.01);
        $this->assertEqualsWithDelta(1_000_000, $reports->salesByProduct(10, null)[0]['revenue'], 0.01);

        $juli = $reports->salesByPartner(User::ROLE_RESELLER, 10, Carbon::parse('2026-07-01'));
        $this->assertEqualsWithDelta(250_000, $juli[0]['revenue'], 0.01);

        $this->assertEqualsWithDelta(250_000, $reports->salesByRegion(Carbon::parse('2026-07-01'))[0]['revenue'], 0.01);
    }
}
