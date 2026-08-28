<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Kol;
use App\Models\KolBudgetTransaction;
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

    /** Pengeluaran tambahan masuk "spent" (bukan CPM). */
    public function test_pengeluaran_tambahan_masuk_spent_bukan_cpm(): void
    {
        $kol = $this->kol();
        AppSetting::put(KolBudgetService::KEY_BUDGET, '5000000');
        KolDeal::create(['kode' => 'E1', 'kol_id' => $kol->id, 'jenis' => 'vt', 'total_biaya' => 1_000_000, 'status' => 'selesai', 'status_bayar' => 'lunas', 'periode_mulai' => now()->toDateString()]);
        KolBudgetTransaction::create(['month' => now()->format('Y-m'), 'category' => 'boost', 'amount' => 500_000]);

        $s = app(KolBudgetService::class)->summary(now());
        $this->assertSame(500_000, $s['extras']);
        $this->assertSame(1_500_000, $s['spent']);   // 1jt deal lunas + 500rb extras
        $this->assertSame(3_500_000, $s['sisa']);     // 5jt − 1,5jt − 0
        $this->assertNull($s['cpm']);                 // tak ada views paid → CPM null (extras tak masuk)
    }

    /** Rincian per-creator terurut biaya + share benar. */
    public function test_per_creator_breakdown_dan_share(): void
    {
        $k1 = $this->kol();
        $k2 = $this->kol();
        AppSetting::put(KolBudgetService::KEY_BUDGET, '10000000');
        KolDeal::create(['kode' => 'P1', 'kol_id' => $k1->id, 'jenis' => 'vt', 'total_biaya' => 4_000_000, 'status' => 'berjalan', 'status_bayar' => 'belum', 'periode_mulai' => now()->toDateString()]);
        KolDeal::create(['kode' => 'P2', 'kol_id' => $k2->id, 'jenis' => 'vt', 'total_biaya' => 1_000_000, 'status' => 'berjalan', 'status_bayar' => 'belum', 'periode_mulai' => now()->toDateString()]);

        $pc = app(KolBudgetService::class)->summary(now())['perCreator'];
        $this->assertCount(2, $pc);
        $this->assertSame(4_000_000, $pc->first()['cost']);   // terurut desc
        $this->assertSame(40, $pc->first()['sharePct']);       // 4jt / 10jt
    }

    /** Month picker: ?bulan scope daftar deal & budget ke bulan itu. */
    public function test_month_picker_scope_deal_dan_budget(): void
    {
        $root = $this->user(User::ROLE_SUPER_ADMIN, 'rootmp');
        $kol = $this->kol();
        KolDeal::create(['kode' => 'MP1', 'kol_id' => $kol->id, 'jenis' => 'vt', 'total_biaya' => 1_000_000, 'status' => 'berjalan', 'status_bayar' => 'lunas', 'periode_mulai' => now()->subMonth()->toDateString()]);
        KolDeal::create(['kode' => 'MP2', 'kol_id' => $kol->id, 'jenis' => 'vt', 'total_biaya' => 2_000_000, 'status' => 'berjalan', 'status_bayar' => 'lunas', 'periode_mulai' => now()->toDateString()]);

        $res = $this->actingAs($root)->get(route('kol-deals.index', ['bulan' => now()->subMonth()->format('Y-m')]))->assertOk();
        $kode = collect($res->viewData('deals')->items())->pluck('kode');
        $this->assertTrue($kode->contains('MP1'));
        $this->assertFalse($kode->contains('MP2'));               // bulan ini tak ikut
        $this->assertSame(1_000_000, $res->viewData('budget')['spent']); // budget bulan lalu
    }

    public function test_budget_tx_store_dan_destroy_finance_only(): void
    {
        $root = $this->user(User::ROLE_SUPER_ADMIN, 'roottx');
        $this->actingAs($root)->post(route('kol-deals.budget-tx.store'), [
            'month' => now()->format('Y-m'), 'category' => 'gift', 'amount' => 300_000, 'note' => 'hampers',
        ])->assertRedirect();
        $tx = KolBudgetTransaction::first();
        $this->assertSame(300_000, $tx->amount);
        $this->assertSame('gift', $tx->category);

        $this->actingAs($root)->delete(route('kol-deals.budget-tx.destroy', $tx))->assertRedirect();
        $this->assertSame(0, KolBudgetTransaction::count());

        // Non-finance (manage saja) → dilarang.
        $this->actingAs($this->user('kol_specialist', 'txnf'))->post(route('kol-deals.budget-tx.store'), [
            'month' => now()->format('Y-m'), 'category' => 'gift', 'amount' => 100_000,
        ])->assertForbidden();
    }

    /** Reminder pembayaran DP menampilkan sisa setelah DP (butuh dp_percent). */
    public function test_reminder_sisa_setelah_dp(): void
    {
        $kol = $this->kol();
        $deal = KolDeal::create(['kode' => 'DPX', 'kol_id' => $kol->id, 'jenis' => 'vt',
            'total_biaya' => 1_000_000, 'status' => 'berjalan', 'status_bayar' => 'dp', 'dp_percent' => 40]);
        $this->assertSame(400_000, $deal->dpAmount());
        $this->assertSame(600_000, $deal->remainingUnpaid());   // 1jt − 40%

        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN, 'rootdp'))->get(route('kol-reminder.index'))
            ->assertOk()->assertSee('DP 40% dibayar')->assertSee('sisa Rp 600.000');

        // 'belum' → sisa = total penuh.
        $d2 = KolDeal::create(['kode' => 'BLM', 'kol_id' => $kol->id, 'jenis' => 'vt',
            'total_biaya' => 500_000, 'status' => 'berjalan', 'status_bayar' => 'belum']);
        $this->assertSame(500_000, $d2->remainingUnpaid());
    }

    public function test_batas_share_configurable(): void
    {
        $kol = $this->kol();
        AppSetting::put(KolBudgetService::KEY_BUDGET, '5000000');
        AppSetting::put('kol_share_limit', '0.7');   // batas 70%
        KolDeal::create(['kode' => 'SL1', 'kol_id' => $kol->id, 'jenis' => 'vt', 'total_biaya' => 3_000_000, 'status' => 'berjalan', 'status_bayar' => 'belum', 'periode_mulai' => now()->toDateString()]);

        $s = app(KolBudgetService::class)->summary(now());
        $this->assertSame(60, $s['topSharePct']);      // 3jt / 5jt
        $this->assertSame(70, $s['shareLimitPct']);
        $this->assertFalse($s['overConcentration']);   // 60% < 70% → tak over
    }
}
