<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Kol;
use App\Models\KolCreatorContent;
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

    public function test_halaman_detail_konten_video_dan_live(): void
    {
        $kol = Kol::create(['tiktok_username' => 'kn', 'followers' => 10_000, 'is_gapok' => true]);
        $period = now()->startOfMonth()->toDateString();
        KolCreatorContent::create(['kol_id' => $kol->id, 'period' => $period, 'type' => 'video',
            'content_id' => '123', 'title' => 'Video Uji Coba', 'views' => 1000, 'gmv' => 500_000, 'sku_orders' => 10]);
        KolCreatorContent::create(['kol_id' => $kol->id, 'period' => $period, 'type' => 'live',
            'content_id' => '456', 'title' => 'LIVE Malam', 'gmv' => 200_000, 'sku_orders' => 5, 'items_sold' => 7]);

        $this->actingAs($this->user('kol_specialist', 'spk'))
            ->get(route('kol-gapok.contents', ['kol' => $kol->id, 'bulan' => now()->format('Y-m')]))
            ->assertOk()->assertSee('Video Uji Coba')->assertSee('LIVE Malam');
    }

    public function test_toggle_set_tanggal_gabung_otomatis(): void
    {
        $kol = Kol::create(['tiktok_username' => 'jd', 'followers' => 5_000]); // belum gapok
        $this->actingAs($this->user('kol_specialist', 'jd1'))
            ->post(route('kol-gapok.toggle'), ['kol_id' => $kol->id, 'is_gapok' => '1'])->assertRedirect();

        $this->assertNotNull($kol->fresh()->gapok_joined_at); // terisi otomatis saat gabung
    }

    public function test_save_join_date_via_http(): void
    {
        $kol = Kol::create(['tiktok_username' => 'jd2', 'followers' => 5_000, 'is_gapok' => true]);

        $this->actingAs($this->user('kol_specialist', 'jd2u'))
            ->postJson(route('kol-gapok.join-date'), ['kol_id' => $kol->id, 'joined_at' => '2026-06-15'])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertSame('2026-06-15', $kol->fresh()->gapok_joined_at->toDateString());
    }

    public function test_pembayaran_cicilan_total_dan_hapus(): void
    {
        $kol = Kol::create(['tiktok_username' => 'pay', 'followers' => 5_000, 'is_gapok' => true]);
        $svc = app(KolGapokService::class);
        $svc->setSalary($kol->id, now(), 1_000_000, null, null);
        $spec = $this->user('kol_specialist', 'payu');
        $bulan = now()->format('Y-m');

        // Cicilan 1: 400rb.
        $this->actingAs($spec)->postJson(route('kol-gapok.payment'), [
            'kol_id' => $kol->id, 'bulan' => $bulan, 'amount' => 400_000, 'paid_at' => now()->toDateString(),
        ])->assertOk()->assertJson(['ok' => true, 'paid_total' => 400_000]);

        // Cicilan 2: 600rb → total 1jt (lunas).
        $r = $this->actingAs($spec)->postJson(route('kol-gapok.payment'), [
            'kol_id' => $kol->id, 'bulan' => $bulan, 'amount' => 600_000, 'paid_at' => now()->toDateString(),
        ])->assertOk()->assertJson(['ok' => true, 'paid_total' => 1_000_000]);

        // Baris performa membawa total dibayar + daftar cicilan.
        $row = $svc->monthly(now())->first();
        $this->assertSame(1_000_000, $row['paid']);
        $this->assertCount(2, $row['payments']);
        $this->assertSame(1_000_000, $svc->totals($svc->monthly(now()))['paid']);

        // Hapus satu cicilan → total turun.
        $this->actingAs($spec)->postJson(route('kol-gapok.payment.delete', ['payment' => $r->json('payment.id')]))
            ->assertOk()->assertJson(['ok' => true, 'paid_total' => 400_000]);
        $this->assertSame(400_000, $svc->paidTotal($kol->id, now()));
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

    public function test_gaji_bulan_baru_otomatis_ikut_bulan_sebelumnya_sampai_disimpan_ulang(): void
    {
        $kol = Kol::create(['tiktok_username' => 'gapokauto', 'followers' => 1, 'is_gapok' => true]);
        $svc = app(KolGapokService::class);
        $svc->setSalary($kol->id, Carbon::parse('2026-08-01'), 2_000_000, null, null);
        $svc->setSalary($kol->id, Carbon::parse('2026-09-01'), 2_500_000, null, null);

        // Oktober belum disimpan → ikut gaji terakhir (Sep), ditandai otomatis.
        $okt = $svc->monthly(Carbon::parse('2026-10-01'))->first();
        $this->assertSame(2_500_000, $okt['salary']);
        $this->assertTrue($okt['salary_auto']);
        $this->assertSame('2026-09-01', $okt['salary_from']);

        // Bulan yang sudah disimpan tetap pakai angkanya sendiri.
        $this->assertFalse($svc->monthly(Carbon::parse('2026-08-01'))->first()['salary_auto']);

        // Disimpan ulang (mis. naik gaji) → angka baru, tak lagi otomatis; bulan sesudahnya ikut angka baru.
        $svc->setSalary($kol->id, Carbon::parse('2026-10-01'), 3_000_000, null, null);
        $okt = $svc->monthly(Carbon::parse('2026-10-01'))->first();
        $this->assertSame([3_000_000, false], [$okt['salary'], $okt['salary_auto']]);
        $this->assertSame(3_000_000, $svc->monthly(Carbon::parse('2026-11-01'))->first()['salary']);

        // Belum pernah punya gaji sama sekali → tetap 0 (tak otomatis).
        $baru = Kol::create(['tiktok_username' => 'gapokbaru', 'followers' => 1, 'is_gapok' => true]);
        $row = $svc->monthly(Carbon::parse('2026-10-01'))->firstWhere('kol.id', $baru->id);
        $this->assertSame([0, false], [$row['salary'], $row['salary_auto']]);
    }

    public function test_simpan_gaji_tanggal_gabung_dan_hapus_bayar_tercatat_di_audit_log(): void
    {
        $kol = Kol::create(['tiktok_username' => 'auditgaji', 'followers' => 1, 'is_gapok' => true]);
        $spec = $this->user('kol_specialist', 'spaudit');
        $bulan = now()->format('Y-m');

        // Simpan gaji 2x: sebelum (null = belum disimpan) -> sesudah; catatan gaji tidak ikut dicatat.
        $this->actingAs($spec)->postJson(route('kol-gapok.salary'), ['kol_id' => $kol->id, 'bulan' => $bulan, 'monthly_salary' => 1_000_000, 'note' => 'CATATAN-GAJI'])->assertOk();
        $this->actingAs($spec)->postJson(route('kol-gapok.salary'), ['kol_id' => $kol->id, 'bulan' => $bulan, 'monthly_salary' => 1_500_000])->assertOk();
        $log = AuditLog::where('action', 'set_gapok_salary')->orderBy('id')->get();
        $this->assertSame([['bulan' => $bulan, 'gaji' => null], ['bulan' => $bulan, 'gaji' => 1_000_000]], $log->pluck('before_data')->all());
        $this->assertSame([1_000_000, 1_500_000], $log->pluck('after_data.gaji')->all());
        $this->assertSame([$kol->id, $kol->id], $log->pluck('target_id')->map(fn ($id) => (int) $id)->all());
        $this->assertStringNotContainsString('CATATAN-GAJI', $log->toJson());

        $this->actingAs($spec)->postJson(route('kol-gapok.join-date'), ['kol_id' => $kol->id, 'joined_at' => '2026-08-01'])->assertOk();
        $join = AuditLog::where('action', 'set_gapok_join_date')->sole();
        $this->assertSame([['tanggal_gabung' => null], ['tanggal_gabung' => '2026-08-01']], [$join->before_data, $join->after_data]);

        // Hapus pembayaran: bulan gaji & tanggal bayar ikut tercatat (bukan cuma nominal).
        $bayar = $this->actingAs($spec)->postJson(route('kol-gapok.payment'), ['kol_id' => $kol->id, 'bulan' => $bulan,
            'amount' => 400_000, 'paid_at' => '2026-10-05'])->assertOk()->json('payment.id');
        $this->actingAs($spec)->postJson(route('kol-gapok.payment.delete', $bayar))->assertOk();
        $this->assertSame(['bulan' => $bulan, 'amount' => 400_000, 'dibayar' => '2026-10-05'],
            AuditLog::where('action', 'delete_gapok_payment')->sole()->before_data);
    }
}
