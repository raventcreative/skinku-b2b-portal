<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\KolAffiliateTransaction;
use App\Models\KolContent;
use App\Models\KolContentSnapshot;
use App\Models\KolDeal;
use App\Models\KolGapokPayment;
use App\Models\KolGapokSalary;
use App\Models\KolMonthlyTarget;
use App\Models\KolPipelineCard;
use App\Models\KolSample;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\KolBudgetService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Alat Asisten AI menu KOL lanjutan — izin & angka = halamannya: Tim Gapok = kol.affiliate.view (+ kol.view),
 * Pipeline / Reminder / Konten & Views = kol.view, Deal KOL = kol.deal.manage saja. Biaya/budget deal hanya
 * kol.deal.finance; bagian Reminder per izin. Rekening, catatan, & nomor resi tak pernah dikirim ke AI.
 */
class AiKolLanjutanToolsTest extends TestCase
{
    use RefreshDatabase;

    private const ALAT = ['tim_gapok', 'pipeline_kol', 'deal_kol', 'reminder_kol', 'konten_views_kol'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-06 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, ?string $u = null): User
    {
        $u ??= $role;

        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function izinkan(string $role, string $perm): void
    {
        RolePermission::create(['role' => $role, 'permission_key' => $perm, 'allowed' => true]);
        Permissions::flushCache();
    }

    private function alat(User $user): array
    {
        $names = array_map(fn ($t) => $t->name(), app(ToolRegistry::class)->forUser($user));

        return array_values(array_intersect(self::ALAT, $names));
    }

    private function pakai(string $alat, User $user, array $args = []): array
    {
        $tool = app(ToolRegistry::class)->find($alat, $user);
        $this->assertNotNull($tool, "alat {$alat} harus tersedia untuk {$user->role}");

        return $tool->run($args, $user);
    }

    private function kol(string $username, array $extra = []): Kol
    {
        return Kol::create(['tiktok_username' => $username, 'followers' => 1] + $extra);
    }

    private function order(Kol $k, string $id, int $gmv, string $tgl, string $tipe = 'VIDEO'): void
    {
        KolAffiliateTransaction::create(['platform' => 'tiktok', 'order_id' => $id, 'kol_id' => $k->id, 'gmv' => $gmv,
            'commission' => intdiv($gmv, 10), 'content_type' => $tipe, 'order_date' => $tgl]);
    }

    private function kartu(Kol $k, string $stage, ?string $tgl, array $extra = []): KolPipelineCard
    {
        return KolPipelineCard::create(['kol_id' => $k->id, 'stage' => $stage, 'next_action' => $tgl ? 'Follow up '.$k->tiktok_username : null,
            'next_action_at' => $tgl] + $extra);
    }

    public function test_alat_tersedia_sesuai_izin_role(): void
    {
        $this->assertSame(self::ALAT, $this->alat($this->user(User::ROLE_SUPER_ADMIN)));
        $this->assertSame(self::ALAT, $this->alat($this->user('kol_specialist')));
        // Admin = penyetuju deal: Deal KOL saja (route deal tak di balik kol.view).
        $this->assertSame(['deal_kol'], $this->alat($this->user(User::ROLE_ADMIN)));
        $this->assertSame([], $this->alat($this->user(User::ROLE_DISTRIBUTOR)));

        // Hanya Database KOL → pipeline, reminder, konten (Tim Gapok butuh izin Affiliate; deal butuh Kelola Deal).
        $this->izinkan(User::ROLE_GUDANG, 'kol.view');
        $this->assertSame(['pipeline_kol', 'reminder_kol', 'konten_views_kol'], $this->alat($this->user(User::ROLE_GUDANG)));

        // Izin Affiliate tanpa Database KOL → Tim Gapok tetap tertutup (halamannya bersarang di kol.view).
        $this->izinkan(User::ROLE_RESELLER, 'kol.affiliate.view');
        $this->assertSame([], $this->alat($this->user(User::ROLE_RESELLER)));
    }

    public function test_tim_gapok_angka_sama_dengan_halaman_tanpa_catatan(): void
    {
        $dewi = $this->kol('dewi.gapok', ['name' => 'Dewi', 'is_gapok' => true, 'gapok_joined_at' => '2026-08-01']);
        $eka = $this->kol('eka.gapok', ['is_gapok' => true]);
        $luar = $this->kol('bukan.gapok');
        $this->order($dewi, 'O1', 3_000_000, '2026-10-02 10:00:00', 'LIVE');
        $this->order($dewi, 'O2', 1_000_000, '2026-10-05 10:00:00');
        $this->order($dewi, 'O3', 7_000_000, '2026-09-20 10:00:00', 'LIVE'); // bulan lalu
        $this->order($eka, 'O4', 500_000, '2026-10-03 10:00:00');
        $this->order($luar, 'O5', 9_000_000, '2026-10-03 10:00:00'); // bukan anggota gapok
        KolGapokSalary::create(['kol_id' => $dewi->id, 'period' => '2026-10-01', 'monthly_salary' => 2_000_000, 'note' => 'GAJI-RAHASIA']);
        KolGapokSalary::create(['kol_id' => $eka->id, 'period' => '2026-09-01', 'monthly_salary' => 1_000_000]); // terbawa ke Oktober
        KolGapokPayment::create(['kol_id' => $dewi->id, 'period' => '2026-10-01', 'amount' => 500_000, 'paid_at' => '2026-10-03', 'note' => 'TRANSFER-RAHASIA']);
        $spesialis = $this->user('kol_specialist');

        $out = $this->pakai('tim_gapok', $spesialis);

        $this->assertSame('2026-10', $out['bulan_gaji']);
        $this->assertSame(['anggota' => 2, 'gmv' => 4_500_000, 'pesanan' => 3, 'komisi' => 450_000, 'video' => 0, 'live' => 0,
            'gaji' => 3_000_000, 'dibayar' => 500_000, 'kurang_bayar' => 2_500_000, 'roi_tim' => 1.5], $out['total']);
        $this->assertSame(['username' => '@dewi.gapok', 'nama' => 'Dewi', 'gabung_sejak' => '2026-08-01', 'gmv' => 4_000_000,
            'gmv_live' => 3_000_000, 'gmv_video' => 1_000_000, 'pesanan' => 2, 'komisi' => 400_000, 'video' => 0, 'live' => 0,
            'gaji' => 2_000_000, 'gaji_otomatis' => false, 'dibayar' => 500_000, 'kurang_bayar' => 1_500_000,
            'status_bayar' => 'kurang', 'roi' => 2.0], $out['anggota'][0]);
        $this->assertSame(['@eka.gapok', 1_000_000, true, 'belum dibayar', 0.5], [$out['anggota'][1]['username'],
            $out['anggota'][1]['gaji'], $out['anggota'][1]['gaji_otomatis'], $out['anggota'][1]['status_bayar'], $out['anggota'][1]['roi']]);

        // Sama dengan footer tabel halaman Tim Gapok.
        $page = $this->actingAs($spesialis)->get(route('kol-gapok.index', ['bulan' => '2026-10']))->assertOk()->viewData('totals');
        $this->assertSame([$page['gmv'], $page['orders'], $page['commission'], $page['salary'], $page['paid'], $page['members']],
            [$out['total']['gmv'], $out['total']['pesanan'], $out['total']['komisi'], $out['total']['gaji'], $out['total']['dibayar'], $out['total']['anggota']]);

        // Rentang harian: GMV ikut rentang, gaji tetap gaji bulanan.
        $r = $this->pakai('tim_gapok', $spesialis, ['dari' => '2026-10-02', 'sampai' => '2026-10-03']);
        $this->assertSame([3_500_000, 3_000_000, 1.5], [$r['total']['gmv'], $r['total']['gaji'], $r['anggota'][0]['roi']]);

        $this->assertStringNotContainsString('RAHASIA', json_encode($out));
    }

    public function test_pipeline_kol_statistik_sama_dengan_papan(): void
    {
        $this->kartu($this->kol('telat'), 'nego', '2026-10-03', ['ask_rate' => 1_500_000, 'final_rate' => 1_200_000,
            'followup_count' => 2, 'negotiation_notes' => 'NEGO-RAHASIA', 'note' => 'CATATAN-RAHASIA']);
        $this->kartu($this->kol('hariini'), 'deal', '2026-10-06');
        $this->kartu($this->kol('besok'), 'sampel_dikirim', '2026-10-07');
        $this->kartu($this->kol('parkir'), 'kandidat', null);
        $this->kartu($this->kol('gugur'), 'drop', '2026-09-01'); // tahap akhir: tak dihitung terlambat
        $this->kartu($this->kol('juara'), 'champion', null, ['track' => KolPipelineCard::TRACK_AFFILIATE]);
        $spesialis = $this->user('kol_specialist');

        $out = $this->pakai('pipeline_kol', $spesialis);

        $this->assertSame('KOL (scouting)', $out['papan']);
        $this->assertSame(['kol' => 5, 'affiliate' => 1], $out['jumlah_kartu_per_papan']);
        $this->assertSame(['total_kartu' => 5, 'aktif' => 4, 'terlambat' => 1, 'next_action_hari_ini_atau_besok' => 2,
            'tanpa_next_action' => 1], $out['ringkasan']);
        $page = $this->actingAs($spesialis)->get(route('kol-pipeline.index'))->assertOk();
        $this->assertSame([$page->viewData('statAktif'), $page->viewData('statTerlambat'), $page->viewData('statDekat'), $page->viewData('statTanpaAksi')],
            [4, 1, 2, 1]);
        $this->assertSame(['tahap' => 'Nego', 'jumlah' => 1], $out['per_tahap'][2]);
        $this->assertSame(['tahap' => 'Drop', 'jumlah' => 1, 'tahap_akhir' => true], $out['per_tahap'][8]);
        // Paling mendesak dulu; tanpa tanggal di belakang; tahap akhir tak ikut.
        $this->assertSame(['@telat', '@hariini', '@besok', '@parkir'], array_column($out['kartu'], 'username'));
        $this->assertSame(['username' => '@telat', 'tahap' => 'Nego', 'next_action' => 'Follow up telat', 'tanggal_next_action' => '2026-10-03',
            'terlambat_hari' => 3, 'follow_up_ke' => 2, 'rate_diminta' => 1_500_000, 'rate_final' => 1_200_000], $out['kartu'][0]);

        // Tahap papan lain tanpa jalur → pindah papan otomatis; label/kunci tahap tak peka huruf besar.
        $aff = $this->pakai('pipeline_kol', $spesialis, ['tahap' => 'champion']);
        $this->assertSame(['Affiliate (pembinaan affiliate)', 'Champion', ['@juara']], [$aff['papan'], $aff['filter_tahap'], array_column($aff['kartu'], 'username')]);
        $this->assertSame(['@besok'], array_column($this->pakai('pipeline_kol', $spesialis, ['tahap' => 'Sampel Dikirim'])['kartu'], 'username'));
        $salah = $this->pakai('pipeline_kol', $spesialis, ['tahap' => 'xyz']);
        $this->assertArrayHasKey('error', $salah);
        $this->assertContains('Kandidat', $salah['tahap_tersedia']);

        $this->assertStringNotContainsString('RAHASIA', json_encode($out));
    }

    public function test_deal_kol_biaya_hanya_untuk_izin_finansial(): void
    {
        $kol = $this->kol('dealkol');
        $pic = $this->user(User::ROLE_ADMIN, 'pic.deal');
        KolDeal::create(['kode' => 'KD-001', 'kol_id' => $kol->id, 'jenis' => 'vt', 'jumlah_slot' => 2, 'deal_type' => 'paid',
            'ratecard_deal' => 2_000_000, 'periode_mulai' => '2026-10-01', 'periode_selesai' => '2026-10-20', 'pic_user_id' => $pic->id,
            'status' => 'berjalan', 'total_biaya' => 4_000_000, 'status_bayar' => 'dp', 'dp_percent' => 50, 'no_rekening' => '9876543210',
            'bank' => 'BANK-RAHASIA', 'atas_nama' => 'REKENING-RAHASIA', 'payment_note' => 'BAYAR-RAHASIA', 'internal_notes' => 'INTERNAL-RAHASIA']);
        KolDeal::create(['kode' => 'KD-002', 'kol_id' => $kol->id, 'jenis' => 'live', 'status' => 'selesai', 'periode_mulai' => '2026-10-02',
            'periode_selesai' => '2026-10-04', 'total_biaya' => 1_000_000, 'status_bayar' => 'lunas', 'hasil_tujuan' => 'penjualan',
            'hasil_video_upload' => 2, 'hasil_video_fyp' => 1, 'hasil_views' => 50_000, 'hasil_revenue' => 3_000_000,
            'hasil_catatan' => 'HASIL-RAHASIA', 'hasil_diisi_at' => '2026-10-05 09:00:00']);
        KolDeal::create(['kode' => 'KD-003', 'kol_id' => $kol->id, 'jenis' => 'vt', 'status' => 'draft', 'periode_mulai' => '2026-09-10']);

        // Admin (Kelola Deal, tanpa Finansial): tanpa biaya/bayar/budget/ROMI.
        $out = $this->pakai('deal_kol', $pic);
        $this->assertSame(['Semua periode', 3], [$out['periode'], $out['jumlah_deal']]);
        $this->assertEquals(['berjalan' => 1, 'selesai' => 1, 'draft' => 1], $out['per_status']);
        $this->assertSame(['KD-003', 'KD-002', 'KD-001'], array_column($out['deal'], 'kode'));
        $this->assertSame(['kode' => 'KD-001', 'kreator' => '@dealkol', 'jenis' => 'VT ×2', 'tipe' => 'Paid promote', 'ratecard' => 2_000_000,
            'periode' => '2026-10-01 s/d 2026-10-20', 'tenggat_posting' => '2026-10-20', 'pic' => 'PIC.DEAL', 'status' => 'berjalan'], $out['deal'][2]);
        $this->assertSame('Bagus', $out['deal'][1]['hasil']);
        $this->assertArrayNotHasKey('budget_bulan', $out);
        $this->assertStringContainsString('Finansial Deal KOL', $out['catatan_akses']);
        $this->assertSame(['Bagus' => 1], $out['laporan_hasil']['per_verdict']);
        $this->assertArrayNotHasKey('total_biaya', $out['laporan_hasil']);
        $this->assertSame(['kode' => 'KD-002', 'kreator' => '@dealkol', 'tujuan' => 'penjualan', 'video_upload' => 2, 'video_fyp' => 1,
            'views' => 50_000, 'rata_views_per_video' => 25_000, 'verdict' => 'Bagus'], $out['laporan_hasil']['teratas'][0]);
        $json = json_encode($out);
        foreach (['RAHASIA', '9876543210', '4000000', 'sisa_tagihan', 'romi'] as $bocor) {
            $this->assertStringNotContainsString($bocor, $json);
        }

        // Super admin (Finansial): biaya, sisa tagihan, budget bulan & total laporan = halaman.
        $super = $this->user(User::ROLE_SUPER_ADMIN);
        $fin = $this->pakai('deal_kol', $super);
        $this->assertSame([4_000_000, 'dp', 2_000_000], [$fin['deal'][2]['total_biaya'], $fin['deal'][2]['status_bayar'], $fin['deal'][2]['sisa_tagihan']]);
        $budget = app(KolBudgetService::class)->summary(now()->startOfMonth());
        $this->assertSame([$budget['spent'], $budget['committed'], $budget['sisa']],
            [$fin['budget_bulan']['spent_lunas'], $fin['budget_bulan']['committed_belum_lunas'], $fin['budget_bulan']['sisa']]);
        $this->assertSame([1_000_000, 4_000_000], [$budget['spent'], $budget['committed']]);
        $page = $this->actingAs($super)->get(route('kol-deals.laporan'))->assertOk()->viewData('totals');
        $this->assertSame([$page['biaya'], $page['views'], $page['revenue'], $page['cpm'], $page['romi']], [$fin['laporan_hasil']['total_biaya'],
            $fin['laporan_hasil']['total_views'], $fin['laporan_hasil']['total_revenue'], $fin['laporan_hasil']['cpm'], $fin['laporan_hasil']['romi']]);
        $this->assertSame([1_000_000, 20_000, 3.0], [$page['biaya'], $page['cpm'], $page['romi']]);
        $this->assertStringNotContainsString('RAHASIA', json_encode($fin));

        // Filter bulan (periode mulai) & status — sebaran status tetap dari semua deal di periode.
        $sep = $this->pakai('deal_kol', $pic, ['bulan' => '2026-09']);
        $this->assertSame(['September 2026', ['KD-003'], ['draft' => 1]], [$sep['periode'], array_column($sep['deal'], 'kode'), $sep['per_status']]);
        $jalan = $this->pakai('deal_kol', $pic, ['status' => 'berjalan']);
        $this->assertSame([1, ['KD-001'], 3], [$jalan['jumlah_deal'], array_column($jalan['deal'], 'kode'), array_sum($jalan['per_status'])]);
    }

    public function test_reminder_kol_bagian_mengikuti_izin(): void
    {
        $this->kartu($this->kol('telat'), 'nego', '2026-10-04');
        $this->kartu($this->kol('hariini'), 'diajak', '2026-10-06', ['track' => KolPipelineCard::TRACK_AFFILIATE]);
        $this->kartu($this->kol('besok'), 'deal', '2026-10-07');
        $this->kartu($this->kol('tanpa'), 'kandidat', null);
        $this->kartu($this->kol('lusa'), 'nego', '2026-10-08');       // belum waktunya
        $this->kartu($this->kol('gugur'), 'drop', '2026-09-01');      // tahap akhir
        $poster = $this->kol('poster');
        KolDeal::create(['kode' => 'PD-1', 'kol_id' => $poster->id, 'jenis' => 'vt', 'status' => 'berjalan', 'posting_deadline' => '2026-10-08']);
        KolDeal::create(['kode' => 'PD-2', 'kol_id' => $poster->id, 'jenis' => 'vt', 'status' => 'berjalan', 'posting_deadline' => '2026-10-20']);
        $sampel = KolSample::create(['kol_id' => $poster->id, 'product' => 'Serum Glow', 'units' => 1, 'status' => 'pending',
            'tracking_no' => 'RESI-RAHASIA', 'notes' => 'SAMPEL-RAHASIA']);
        KolSample::where('id', $sampel->id)->update(['created_at' => now()->subDays(5)]);
        $this->order($this->kol('diam'), 'OD1', 200_000, '2026-10-01 10:00:00');
        KolDeal::create(['kode' => 'PAY-1', 'kol_id' => $poster->id, 'jenis' => 'live', 'status' => 'selesai', 'periode_selesai' => '2026-10-01',
            'total_biaya' => 3_000_000, 'status_bayar' => 'belum', 'no_rekening' => '1122334455']);

        // KOL Specialist: pipeline + deadline + sampel + affiliate diam; tagihan tidak (bukan finance).
        $spesialis = $this->user('kol_specialist');
        $out = $this->pakai('reminder_kol', $spesialis);
        $this->assertSame(['terlambat' => 1, 'hari_ini' => 1, 'besok' => 1, 'tanpa_next_action' => 1],
            array_intersect_key($out['pipeline'], array_flip(['terlambat', 'hari_ini', 'besok', 'tanpa_next_action'])));
        $page = $this->actingAs($spesialis)->get(route('kol-reminder.index'))->assertOk();
        $this->assertSame([1, 1, 1, 1], [$page->viewData('lateCount'), $page->viewData('dueCount'), $page->viewData('besokCount'), $page->viewData('noneCount')]);
        $this->assertSame(['@telat', '@hariini', '@besok', '@tanpa'], array_column($out['pipeline']['daftar'], 'username'));
        $this->assertSame(['terlambat', 'hari ini', 'besok', 'tanpa next action'], array_column($out['pipeline']['daftar'], 'kategori'));
        $this->assertSame(['kategori' => 'hari ini', 'username' => '@hariini', 'papan' => 'Affiliate', 'tahap' => 'Diajak',
            'next_action' => 'Follow up hariini', 'tanggal' => '2026-10-06'], $out['pipeline']['daftar'][1]);
        $this->assertSame(2, $out['pipeline']['daftar'][0]['terlambat_hari']);
        $this->assertSame([['kode' => 'PD-1', 'kreator' => '@poster', 'jenis' => 'VT', 'tenggat' => '2026-10-08', 'lewat_tenggat' => false]],
            $out['deadline_posting']['daftar']);
        $this->assertSame([['produk' => 'Serum Glow', 'kreator' => '@poster', 'status' => 'belum dikirim', 'hari' => 5]], $out['sampel_tertahan']['daftar']);
        $this->assertSame(['@diam'], $out['affiliate_berhenti_posting']['kreator']);
        $this->assertArrayNotHasKey('tagihan_belum_lunas', $out);
        $this->assertStringContainsString('Finansial Deal KOL', $out['catatan_akses']);
        foreach (['RAHASIA', '1122334455'] as $bocor) {
            $this->assertStringNotContainsString($bocor, json_encode($out));
        }

        // Hanya Database KOL: pipeline & deadline saja.
        $this->izinkan(User::ROLE_GUDANG, 'kol.view');
        $gudang = $this->pakai('reminder_kol', $this->user(User::ROLE_GUDANG));
        $this->assertSame([], array_intersect(['sampel_tertahan', 'affiliate_berhenti_posting', 'tagihan_belum_lunas'], array_keys($gudang)));
        $this->assertSame(4, $gudang['pipeline']['terlambat'] + $gudang['pipeline']['hari_ini'] + $gudang['pipeline']['besok'] + $gudang['pipeline']['tanpa_next_action']);
        $this->assertStringContainsString('Kelola Deal KOL', $gudang['catatan_akses']);

        // Super admin: + tagihan belum lunas (sama dgn KolBudgetService::unpaid di halaman).
        $super = $this->pakai('reminder_kol', $this->user(User::ROLE_SUPER_ADMIN));
        $this->assertSame(app(KolBudgetService::class)->unpaid()->count(), $super['tagihan_belum_lunas']['jumlah']);
        $pay = collect($super['tagihan_belum_lunas']['daftar'])->firstWhere('kode', 'PAY-1');
        $this->assertSame(['kode' => 'PAY-1', 'kreator' => '@poster', 'status_bayar' => 'belum', 'total_biaya' => 3_000_000,
            'sisa_tagihan' => 3_000_000, 'tenggat' => '2026-10-01', 'lewat_tenggat' => true], $pay);
        $this->assertArrayNotHasKey('catatan_akses', $super);
        $this->assertStringNotContainsString('1122334455', json_encode($super));
    }

    public function test_konten_views_kol_angka_sama_dengan_halaman(): void
    {
        $a = $this->kol('konten.a', ['name' => 'Konten A']);
        $b = $this->kol('konten.b');
        $deal = KolDeal::create(['kode' => 'KV-1', 'kol_id' => $a->id, 'jenis' => 'vt', 'status' => 'berjalan', 'periode_mulai' => '2026-10-01']);
        $c1 = KolContent::create(['kol_id' => $a->id, 'kol_deal_id' => $deal->id, 'url' => 'https://www.tiktok.com/@konten.a/video/1',
            'title' => 'Review serum', 'label' => 'paid', 'content_type' => 'video', 'posted_at' => '2026-10-02', 'notes' => 'CATATAN-RAHASIA']);
        $c2 = KolContent::create(['kol_id' => $b->id, 'url' => 'https://www.tiktok.com/@konten.b/video/2', 'label' => 'earned',
            'content_type' => 'video', 'posted_at' => '2026-10-03']);
        $c3 = KolContent::create(['kol_id' => $b->id, 'url' => 'https://www.tiktok.com/@konten.b/video/3', 'label' => 'earned', 'posted_at' => '2026-09-28']);
        $snap = fn (KolContent $c, int $views, string $tgl, ?int $likes = null, ?int $komen = null) => KolContentSnapshot::create([
            'kol_content_id' => $c->id, 'views' => $views, 'likes' => $likes, 'comments' => $komen, 'captured_on' => $tgl, 'source' => 'manual']);
        $snap($c1, 40_000, '2026-10-05', 2_000, 100);
        $snap($c2, 5_000, '2026-10-04');
        $snap($c2, 10_000, '2026-10-05'); // snapshot terbaru yang dipakai
        $snap($c3, 99_000, '2026-10-05'); // diposting September → tak dihitung di Oktober
        KolMonthlyTarget::create(['month' => '2026-10', 'views_target' => 500_000]);
        $spesialis = $this->user('kol_specialist');

        $out = $this->pakai('konten_views_kol', $spesialis);

        $this->assertSame([50_000, 40_000, 10_000, 500_000, 10], [$out['total_views'], $out['views_paid'], $out['views_earned'],
            $out['target_views'], $out['persen_target']]);
        // Proyeksi = 50.000 × 31/6 hari; butuh (500.000 − 50.000) ÷ 26 hari tersisa.
        $this->assertSame([258_333, 'di bawah target', 26, 17_308], [$out['proyeksi_akhir_bulan'], $out['status_target'],
            $out['sisa_hari'], $out['butuh_views_per_hari']]);
        $page = $this->actingAs($spesialis)->get(route('kol-konten.index', ['bulan' => '2026-10']))->assertOk();
        $this->assertSame([$page->viewData('total'), $page->viewData('paid'), $page->viewData('target'), $page->viewData('proj'), $page->viewData('perDayNeeded')],
            [$out['total_views'], $out['views_paid'], $out['target_views'], $out['proyeksi_akhir_bulan'], $out['butuh_views_per_hari']]);
        $this->assertSame([2, ['Video' => 2]], [$out['jumlah_konten'], $out['per_tipe']]);
        $this->assertEquals(['paid' => 1, 'earned' => 1], $out['per_label']);
        $this->assertSame([['username' => '@konten.a', 'konten' => 1, 'views' => 40_000], ['username' => '@konten.b', 'konten' => 1, 'views' => 10_000]],
            $out['kreator_teratas']);
        $this->assertSame(['judul' => 'Review serum', 'kreator' => '@konten.a', 'label' => 'paid', 'tipe' => 'Video', 'platform' => 'tiktok',
            'tanggal' => '2026-10-02', 'views' => 40_000, 'like' => 2_000, 'komen' => 100, 'engagement_rate_persen' => 5.25, 'deal' => 'KV-1',
            'link' => 'https://www.tiktok.com/@konten.a/video/1'], $out['konten_teratas'][0]);
        $this->assertStringNotContainsString('RAHASIA', json_encode($out));

        // Satu kreator: target tim tidak dibandingkan ke satu kreator.
        $satu = $this->pakai('konten_views_kol', $spesialis, ['username' => '@konten.b']);
        $this->assertSame(['username' => '@konten.b', 'nama' => null], $satu['kreator']);
        $this->assertSame(10_000, $satu['total_views']);
        $this->assertArrayNotHasKey('target_views', $satu);
        $this->assertArrayNotHasKey('kreator_teratas', $satu);
        $this->assertStringContainsString('seluruh tim', $satu['catatan']);

        // Bulan lalu: konten September saja, tanpa proyeksi.
        $sep = $this->pakai('konten_views_kol', $spesialis, ['bulan' => '2026-09']);
        $this->assertSame([99_000, 1], [$sep['total_views'], $sep['jumlah_konten']]);
        $this->assertArrayNotHasKey('proyeksi_akhir_bulan', $sep);
    }
}
