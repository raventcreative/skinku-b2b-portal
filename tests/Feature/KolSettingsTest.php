<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Kol;
use App\Models\KolMonthlyTarget;
use App\Models\User;
use App\Services\KolBudgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class KolSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_gating_finance_only(): void
    {
        // kol_specialist tak punya kol.deal.finance → dilarang.
        $this->actingAs($this->user('kol_specialist', 'ks'))->get(route('kol-settings.index'))->assertForbidden();
        // super_admin (finance implisit) → boleh.
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN, 'root'))->get(route('kol-settings.index'))
            ->assertOk()->assertSee('Setelan Global');
    }

    public function test_simpan_setelan_global(): void
    {
        $root = $this->user(User::ROLE_SUPER_ADMIN, 'root2');
        $this->actingAs($root)->post(route('kol-settings.save'), [
            'budget' => 7_000_000, 'anchor' => 6_000, 'views_target' => 2_000_000,
            'gmv_target' => 50_000_000, 'margin_pct' => 45, 'sample_hpp' => 25_000, 'date_order' => 'dmy',
        ])->assertRedirect();

        $this->assertSame('7000000', AppSetting::get(KolBudgetService::KEY_BUDGET));
        $this->assertSame('6000', AppSetting::get(KolBudgetService::KEY_ANCHOR));
        $this->assertSame('2000000', AppSetting::get('kol_views_target'));
        $this->assertSame('50000000', AppSetting::get('kol_gmv_target'));
        $this->assertSame('0.45', AppSetting::get('kol_gross_margin'));   // 45% → 0.45
        $this->assertSame('25000', AppSetting::get('kol_sample_hpp'));
        $this->assertSame('dmy', AppSetting::get('kol_import_date_order'));
    }

    public function test_override_bulanan_menang_atas_budget_global(): void
    {
        AppSetting::put(KolBudgetService::KEY_BUDGET, '5000000');
        $m = now()->startOfMonth();

        // Tanpa override → pakai global.
        $this->assertSame(5_000_000, app(KolBudgetService::class)->summary($m)['budget']);

        // Dengan override bulan ini → menang.
        KolMonthlyTarget::create(['month' => $m->format('Y-m'), 'budget' => 9_000_000]);
        $this->assertSame(9_000_000, app(KolBudgetService::class)->summary($m)['budget']);

        // Bulan lain tanpa override → tetap global.
        $this->assertSame(5_000_000, app(KolBudgetService::class)->summary($m->copy()->subMonth())['budget']);
    }

    public function test_override_bulanan_ubah_target_views_dashboard(): void
    {
        $root = $this->user(User::ROLE_SUPER_ADMIN, 'root3');
        AppSetting::put('kol_views_target', '1000000');
        KolMonthlyTarget::create(['month' => now()->format('Y-m'), 'views_target' => 3_000_000, 'margin' => 0.6]);

        $res = $this->actingAs($root)->get(route('kol-dashboard.index'))->assertOk();
        $this->assertSame(3_000_000, $res->viewData('target'));   // override menang
        $this->assertSame(0.6, $res->viewData('margin'));
    }

    public function test_store_dan_hapus_override_bulanan(): void
    {
        $root = $this->user(User::ROLE_SUPER_ADMIN, 'root4');

        $this->actingAs($root)->post(route('kol-settings.monthly.store'), [
            'month' => '2026-09', 'budget' => 8_000_000, 'margin_pct' => 50,
        ])->assertRedirect();
        $row = KolMonthlyTarget::where('month', '2026-09')->first();
        $this->assertNotNull($row);
        $this->assertSame(8_000_000, $row->budget);
        $this->assertSame(0.5, $row->margin);
        $this->assertNull($row->views_target);   // kosong = ikut global

        $this->actingAs($root)->delete(route('kol-settings.monthly.destroy', $row))->assertRedirect();
        $this->assertNull(KolMonthlyTarget::where('month', '2026-09')->first());
    }
}
