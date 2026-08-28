<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Kol;
use App\Models\KolContent;
use App\Models\KolDeal;
use App\Models\KolPipelineCard;
use App\Models\User;
use App\Services\KolAffiliateService;
use App\Services\KolBudgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class KolDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_gating_dan_render_ringkasan(): void
    {
        $this->actingAs($this->user(User::ROLE_RESELLER, 'r1'))->get(route('kol-dashboard.index'))->assertForbidden();

        $spec = $this->user('kol_specialist', 'ks1');
        $kol = Kol::create(['tiktok_username' => 'dashkol', 'followers' => 40_000]);
        KolPipelineCard::create(['kol_id' => $kol->id, 'stage' => 'nego', 'next_action' => 'x', 'next_action_at' => now()->subDay()->toDateString()]);
        $c = KolContent::create(['kol_id' => $kol->id, 'url' => 'https://www.tiktok.com/@x/v/1', 'label' => 'earned', 'posted_at' => now()->toDateString()]);
        $c->snapshots()->create(['views' => 50_000, 'captured_on' => now()->startOfDay(), 'source' => 'manual']);

        $res = $this->actingAs($spec)->get(route('kol-dashboard.index'))->assertOk()->assertSee('Dashboard KOL');
        $this->assertSame(1, $res->viewData('pipeline')['active']);
        $this->assertSame(1, $res->viewData('pipeline')['terlambat']);
        $this->assertSame(50_000, $res->viewData('totalViews'));
    }

    public function test_month_nav_roas_roi_dan_empty_state(): void
    {
        // Tanpa data → empty-state onboarding.
        $spec = $this->user('kol_specialist', 'dashe');
        $this->actingAs($spec)->get(route('kol-dashboard.index'))->assertOk()->assertSee('Mulai dari sini');

        // Finance + data → ROAS/ROI terhitung.
        $root = $this->user(User::ROLE_SUPER_ADMIN, 'dashr');
        AppSetting::put(KolBudgetService::KEY_BUDGET, '5000000');
        AppSetting::put('kol_gross_margin', '0.5');
        $kol = Kol::create(['tiktok_username' => 'droas', 'followers' => 50_000]);
        KolDeal::create(['kode' => 'DR', 'kol_id' => $kol->id, 'jenis' => 'vt', 'total_biaya' => 1_000_000,
            'status' => 'berjalan', 'status_bayar' => 'lunas', 'periode_mulai' => now()->toDateString()]);
        app(KolAffiliateService::class)->import([
            ['order_id' => 'DR1', 'username' => 'droas', 'gmv' => 4_000_000, 'order_date' => now()->toDateString()],
        ], 'tiktok', $root->id);

        $res = $this->actingAs($root)->get(route('kol-dashboard.index'))->assertOk()->assertSee('ROAS')->assertSee('ROI');
        $this->assertSame(4.0, $res->viewData('roas'));   // 4jt ÷ 1jt
        $this->assertSame(1.0, $res->viewData('roi'));    // (4jt×0,5 − 1jt) ÷ 1jt

        // Navigasi bulan lampau → arsip.
        $this->actingAs($root)->get(route('kol-dashboard.index', ['bulan' => now()->subMonth()->format('Y-m')]))
            ->assertOk()->assertSee('arsip');
    }

    /** Daftar pengingat (≤8) + kartu GMV % target di dashboard. */
    public function test_daftar_pengingat_dan_gmv_target(): void
    {
        $root = $this->user(User::ROLE_SUPER_ADMIN, 'dashrem');
        $kol = Kol::create(['tiktok_username' => 'remk', 'followers' => 30_000]);
        KolPipelineCard::create(['kol_id' => $kol->id, 'stage' => 'nego', 'next_action' => 'follow up', 'next_action_at' => now()->subDay()->toDateString()]);
        AppSetting::put('kol_gmv_target', '10000000');
        app(KolAffiliateService::class)->import([
            ['order_id' => 'G1', 'username' => 'remk', 'gmv' => 5_000_000, 'order_date' => now()->toDateString()],
        ], 'tiktok', $root->id);

        $res = $this->actingAs($root)->get(route('kol-dashboard.index'))->assertOk()
            ->assertSee('Pengingat terdekat')->assertSee('dari target');
        $this->assertCount(1, $res->viewData('reminders'));
        $this->assertSame(10_000_000, $res->viewData('gmvTarget'));   // 5jt = 50% target
    }

    /** Kartu CPM paid + banner peringatan budget: datanya sudah dihitung KolBudgetService, kini tampil (finance-only). */
    public function test_kartu_cpm_dan_banner_peringatan_budget_finance(): void
    {
        AppSetting::put(KolBudgetService::KEY_BUDGET, '5000000');
        AppSetting::put(KolBudgetService::KEY_ANCHOR, '5000');

        $kol = Kol::create(['tiktok_username' => 'budgethealth', 'followers' => 40_000]);
        // 1 KOL menyerap 3jt/5jt = 60% (>40% → overConcentration).
        KolDeal::create(['kode' => 'BH1', 'kol_id' => $kol->id, 'jenis' => 'vt', 'total_biaya' => 3_000_000, 'status' => 'berjalan', 'status_bayar' => 'belum', 'periode_mulai' => now()->toDateString()]);
        // Konten paid 300rb views → CPM 3jt/300 = 10.000 > anchor 5.000 (overAnchor).
        $c = KolContent::create(['kol_id' => $kol->id, 'url' => 'https://www.tiktok.com/@x/v/9', 'label' => 'paid', 'posted_at' => now()->toDateString()]);
        $c->snapshots()->create(['views' => 300_000, 'captured_on' => now()->startOfDay(), 'source' => 'manual']);

        // Finance (super_admin) melihat kartu CPM + banner peringatan.
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN, 'rootbh'))->get(route('kol-dashboard.index'))->assertOk()
            ->assertSee('CPM paid (blended)')
            ->assertSee('Peringatan budget')
            ->assertSee('di atas anchor')
            ->assertSee('1 KOL menyerap')
            ->assertSee('60%');

        // Non-finance tak lihat (budget null → tak ada kartu/banner).
        $this->actingAs($this->user('kol_specialist', 'ksbh'))->get(route('kol-dashboard.index'))->assertOk()
            ->assertDontSee('CPM paid (blended)')
            ->assertDontSee('Peringatan budget');
    }
}
