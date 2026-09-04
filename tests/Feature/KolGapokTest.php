<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\KolCreatorContentStat;
use App\Models\User;
use App\Services\KolAffiliateService;
use App\Services\KolGapokService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class KolGapokTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_monthly_hanya_gapok_dengan_gmv_split_dan_roi(): void
    {
        $gapok = Kol::create(['tiktok_username' => 'gapok1', 'followers' => 10_000, 'is_gapok' => true]);
        Kol::create(['tiktok_username' => 'nongapok', 'followers' => 5_000]); // default is_gapok=false

        app(KolAffiliateService::class)->import([
            ['order_id' => 'G1', 'username' => 'gapok1', 'gmv' => 1_000_000, 'commission' => 100_000, 'content_type' => 'LIVE', 'order_date' => now()->toDateString()],
            ['order_id' => 'G2', 'username' => 'gapok1', 'gmv' => 500_000, 'commission' => 50_000, 'content_type' => 'VIDEO', 'order_date' => now()->toDateString()],
            ['order_id' => 'G3', 'username' => 'gapok1', 'gmv' => 999_000, 'status' => 'Cancelled', 'order_date' => now()->toDateString()], // batal → skip
            ['order_id' => 'N1', 'username' => 'nongapok', 'gmv' => 2_000_000, 'order_date' => now()->toDateString()], // bukan gapok → tak muncul
        ], 'tiktok', null);

        $svc = app(KolGapokService::class);
        $svc->setSalary($gapok->id, now(), 500_000, 'gaji sept', null);

        $rows = $svc->monthly(now());
        $this->assertCount(1, $rows); // cuma anggota gapok
        $r = $rows->first();
        $this->assertSame($gapok->id, $r['kol']->id);
        $this->assertSame(1_500_000, $r['gmv']);   // 1jt + 500rb, batal dikecualikan
        $this->assertSame(2, $r['orders']);
        $this->assertSame(150_000, $r['commission']);
        $this->assertSame(1_000_000, $r['gmv_live']);
        $this->assertSame(500_000, $r['gmv_video']);
        $this->assertSame(500_000, $r['salary']);
        $this->assertSame(3.0, $r['roi']);          // 1,5jt / 500rb

        $totals = $svc->totals($rows);
        $this->assertSame(1_500_000, $totals['gmv']);
        $this->assertSame(500_000, $totals['salary']);
        $this->assertSame(1, $totals['members']);
    }

    public function test_anggota_tanpa_gaji_roi_null_tetap_muncul(): void
    {
        $kol = Kol::create(['tiktok_username' => 'belumgaji', 'followers' => 8_000, 'is_gapok' => true]);
        $rows = app(KolGapokService::class)->monthly(now());

        $this->assertCount(1, $rows);
        $this->assertSame($kol->id, $rows->first()['kol']->id);
        $this->assertSame(0, $rows->first()['gmv']);
        $this->assertNull($rows->first()['roi']); // tanpa gaji → ROI tak dihitung
    }

    public function test_set_salary_isi_ulang_memperbarui(): void
    {
        $kol = Kol::create(['tiktok_username' => 'ubahgaji', 'followers' => 8_000, 'is_gapok' => true]);
        $svc = app(KolGapokService::class);
        $svc->setSalary($kol->id, now(), 300_000, null, null);
        $svc->setSalary($kol->id, now(), 750_000, null, null); // isi ulang bulan sama

        $this->assertSame(1, $kol->gapokSalaries()->count());
        $this->assertSame(750_000, (int) $kol->gapokSalaries()->first()->monthly_salary);
    }

    public function test_halaman_render_dan_gate_izin(): void
    {
        Kol::create(['tiktok_username' => 'gp', 'name' => 'Gapok Satu', 'followers' => 10_000, 'is_gapok' => true]);

        // gudang tak punya kol.affiliate.view → forbidden
        $this->actingAs($this->user(User::ROLE_GUDANG, 'gd1'))->get(route('kol-gapok.index'))->assertForbidden();

        // kol_specialist → OK + nampilin anggota
        $this->actingAs($this->user('kol_specialist', 'sp1'))->get(route('kol-gapok.index'))
            ->assertOk()->assertSee('Tim Affiliate Gapok')->assertSee('Gapok Satu');
    }

    public function test_toggle_tambah_dan_keluarkan_anggota(): void
    {
        $kol = Kol::create(['tiktok_username' => 'calon', 'followers' => 5_000]); // belum gapok
        $spec = $this->user('kol_specialist', 'sp2');

        $this->actingAs($spec)->post(route('kol-gapok.toggle'), ['kol_id' => $kol->id, 'is_gapok' => '1'])->assertRedirect();
        $this->assertTrue($kol->fresh()->is_gapok);

        $this->actingAs($spec)->post(route('kol-gapok.toggle'), ['kol_id' => $kol->id, 'is_gapok' => '0'])->assertRedirect();
        $this->assertFalse($kol->fresh()->is_gapok);
    }

    public function test_save_salary_via_http(): void
    {
        $kol = Kol::create(['tiktok_username' => 'gaji', 'followers' => 5_000, 'is_gapok' => true]);

        $this->actingAs($this->user('kol_specialist', 'sp3'))->post(route('kol-gapok.salary'), [
            'kol_id' => $kol->id, 'bulan' => now()->format('Y-m'), 'monthly_salary' => 1_000_000,
        ])->assertRedirect();

        $this->assertSame(1_000_000, (int) $kol->gapokSalaries()->first()->monthly_salary);
    }

    public function test_add_by_username_bikin_kol_baru_lalu_tandai(): void
    {
        $spec = $this->user('kol_specialist', 'spu');

        // Username baru (belum jadi KOL) → dibuatin + ditandai gapok.
        $this->actingAs($spec)->post(route('kol-gapok.add-username'), ['username' => '@dianci22'])->assertRedirect();
        $baru = Kol::whereRaw('LOWER(tiktok_username) = ?', ['dianci22'])->first();
        $this->assertNotNull($baru);
        $this->assertTrue($baru->is_gapok);
        $this->assertSame('affiliate', $baru->role);

        // Username yang sudah jadi KOL → cukup ditandai gapok.
        $ada = Kol::create(['tiktok_username' => 'sudahada', 'followers' => 100]);
        $this->actingAs($spec)->post(route('kol-gapok.add-username'), ['username' => 'sudahada'])->assertRedirect();
        $this->assertTrue($ada->fresh()->is_gapok);
    }

    public function test_save_salary_ajax_balikin_json(): void
    {
        $kol = Kol::create(['tiktok_username' => 'gajax', 'followers' => 5_000, 'is_gapok' => true]);

        $this->actingAs($this->user('kol_specialist', 'spj'))
            ->postJson(route('kol-gapok.salary'), ['kol_id' => $kol->id, 'bulan' => now()->format('Y-m'), 'monthly_salary' => 2_000_000])
            ->assertOk()->assertJson(['ok' => true, 'salary' => 2_000_000]);

        $this->assertSame(2_000_000, (int) $kol->gapokSalaries()->first()->monthly_salary);
    }

    public function test_video_live_count_muncul_di_gapok(): void
    {
        $kol = Kol::create(['tiktok_username' => 'vc', 'followers' => 10_000, 'is_gapok' => true]);
        KolCreatorContentStat::create([
            'kol_id' => $kol->id, 'period' => now()->startOfMonth()->toDateString(), 'videos' => 12, 'lives' => 3,
        ]);

        $rows = app(KolGapokService::class)->monthly(now());
        $r = $rows->first();
        $this->assertSame(12, $r['videos']);
        $this->assertSame(3, $r['lives']);

        $totals = app(KolGapokService::class)->totals($rows);
        $this->assertSame(12, $totals['videos']);
        $this->assertSame(3, $totals['lives']);
    }

    public function test_range_menyaring_per_tanggal(): void
    {
        $kol = Kol::create(['tiktok_username' => 'rg', 'followers' => 10_000, 'is_gapok' => true]);
        app(KolAffiliateService::class)->import([
            ['order_id' => 'D1', 'username' => 'rg', 'gmv' => 100_000, 'order_date' => '2026-09-01'],
            ['order_id' => 'D2', 'username' => 'rg', 'gmv' => 200_000, 'order_date' => '2026-09-10'],
        ], 'tiktok', null);

        $svc = app(KolGapokService::class);
        $sal = Carbon::parse('2026-09-01');

        // 1–5 Sep → hanya D1
        $r = $svc->range(Carbon::parse('2026-09-01')->startOfDay(), Carbon::parse('2026-09-05')->endOfDay(), $sal);
        $this->assertSame(100_000, $r->first()['gmv']);

        // 1–15 Sep → dua-duanya
        $r2 = $svc->range(Carbon::parse('2026-09-01')->startOfDay(), Carbon::parse('2026-09-15')->endOfDay(), $sal);
        $this->assertSame(300_000, $r2->first()['gmv']);
    }
}
