<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Kol;
use App\Models\KolContent;
use App\Models\KolDeal;
use App\Models\KolPipelineCard;
use App\Models\User;
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
