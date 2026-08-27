<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Kol;
use App\Models\KolContent;
use App\Models\KolDeal;
use App\Models\User;
use App\Services\KolBudgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class KolBudgetTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function kol(): Kol
    {
        static $n = 0;
        $n++;

        return Kol::create(['tiktok_username' => "budgetkol{$n}", 'followers' => 50_000]);
    }

    public function test_budget_summary_math(): void
    {
        $kol = $this->kol();
        AppSetting::put(KolBudgetService::KEY_BUDGET, '5000000');
        AppSetting::put(KolBudgetService::KEY_ANCHOR, '5000');

        KolDeal::create(['kode' => 'D1', 'kol_id' => $kol->id, 'jenis' => 'vt', 'total_biaya' => 1_000_000, 'status' => 'selesai', 'status_bayar' => 'lunas', 'periode_mulai' => now()->toDateString()]);
        KolDeal::create(['kode' => 'D2', 'kol_id' => $kol->id, 'jenis' => 'vt', 'total_biaya' => 2_000_000, 'status' => 'berjalan', 'status_bayar' => 'belum', 'periode_mulai' => now()->toDateString()]);
        KolDeal::create(['kode' => 'D3', 'kol_id' => $kol->id, 'jenis' => 'vt', 'total_biaya' => 9_000_000, 'status' => 'batal', 'status_bayar' => 'belum', 'periode_mulai' => now()->toDateString()]);

        // Konten paid 600rb views bulan ini → CPM = 3jt / (600rb/1000) = 5.000.
        $c = KolContent::create(['kol_id' => $kol->id, 'url' => 'https://www.tiktok.com/@x/v/1', 'label' => 'paid', 'posted_at' => now()->toDateString()]);
        $c->snapshots()->create(['views' => 600_000, 'captured_on' => now()->startOfDay(), 'source' => 'manual']);

        $s = app(KolBudgetService::class)->summary(now());
        $this->assertSame(1_000_000, $s['spent']);
        $this->assertSame(2_000_000, $s['committed']);        // batal diabaikan
        $this->assertSame(2_000_000, $s['sisa']);             // 5jt − 1jt − 2jt
        $this->assertSame(5000, $s['cpm']);                   // 3jt / 600
        $this->assertFalse($s['overAnchor']);                 // 5000 == anchor, bukan >
        $this->assertSame(60, $s['topSharePct']);             // 3jt / 5jt
        $this->assertTrue($s['overConcentration']);
    }

    public function test_panel_budget_finance_only(): void
    {
        $res = $this->actingAs($this->user('kol_specialist', 'ksm1'))->get(route('kol-deals.index'))->assertOk();
        $this->assertNull($res->viewData('budget')); // manage tanpa finance → tak lihat budget

        $res2 = $this->actingAs($this->user(User::ROLE_SUPER_ADMIN, 'root9'))->get(route('kol-deals.index'))->assertOk();
        $this->assertNotNull($res2->viewData('budget'));
    }

    public function test_save_budget(): void
    {
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN, 'root8'))
            ->post(route('kol-deals.budget'), ['budget' => 7_000_000, 'anchor' => 6000])->assertRedirect();
        $this->assertSame('7000000', AppSetting::get(KolBudgetService::KEY_BUDGET));
        $this->assertSame('6000', AppSetting::get(KolBudgetService::KEY_ANCHOR));
    }

    public function test_reminder_pembayaran_finance(): void
    {
        KolDeal::create(['kode' => 'DP', 'kol_id' => $this->kol()->id, 'jenis' => 'vt', 'total_biaya' => 500_000, 'status' => 'berjalan', 'status_bayar' => 'belum']);

        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN, 'root7'))->get(route('kol-reminder.index'))
            ->assertOk()->assertViewHas('payments', fn ($p) => $p->count() === 1);
        $this->actingAs($this->user('kol_specialist', 'ksm2'))->get(route('kol-reminder.index'))
            ->assertViewHas('payments', fn ($p) => $p->isEmpty()); // non-finance tak lihat tagihan
    }
}
