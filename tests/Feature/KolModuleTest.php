<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Kol;
use App\Models\KolScreening;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class KolModuleTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function kol(int $followers = 50_000): Kol
    {
        static $n = 0;
        $n++;

        return Kol::create(['tiktok_username' => "kol{$n}", 'followers' => $followers]);
    }

    /** Semua route KOL tertutup untuk siapa pun tanpa kol.view — termasuk mitra & afiliator. */
    public function test_tanpa_kol_view_semua_route_403(): void
    {
        $kol = $this->kol();

        foreach ([
            $this->user(User::ROLE_DISTRIBUTOR, 'mitra1'),
            $this->user(User::ROLE_RESELLER, 'mitra2'),
            $this->user('affiliator', 'afil1'),   // role dinamis, bukan staf/mitra
            $this->user(User::ROLE_GUDANG, 'gud1'),
        ] as $user) {
            $this->actingAs($user)->get(route('kols.index'))->assertForbidden();
            $this->actingAs($user)->get(route('kols.show', $kol))->assertForbidden();
            $this->actingAs($user)->get(route('kol-deals.index'))->assertForbidden();
            $this->actingAs($user)->post(route('kols.store'), [])->assertForbidden();
        }
    }

    public function test_kol_specialist_bisa_membuka_daftar_dan_menambah_kol(): void
    {
        $spec = $this->user('kol_specialist', 'spec1');

        $this->actingAs($spec)->get(route('kols.index'))->assertOk()->assertSee('Database KOL');

        $this->actingAs($spec)->post(route('kols.store'), [
            'tiktok_username' => 'skincarequeen', 'followers' => 250_000, 'kategori' => 'Skinfluencer',
        ])->assertRedirect();

        $this->assertSame('Middle', Kol::where('tiktok_username', 'skincarequeen')->first()->level);
    }

    /**
     * Batas level TEPAT di angka batas: angka batas naik ke jenjang atasnya,
     * kecuali 2,5jt yang masih Mega (rentang "1jt–2,5jt" inklusif).
     */
    public function test_level_otomatis_benar_di_batas_batas(): void
    {
        $cases = [
            [9_999, 'Nano'], [10_000, 'Mikro'],
            [99_999, 'Mikro'], [100_000, 'Middle'],
            [499_999, 'Middle'], [500_000, 'Makro'],
            [999_999, 'Makro'], [1_000_000, 'Mega'],
            [2_500_000, 'Mega'], [2_500_001, 'Super Mega'],
        ];

        foreach ($cases as [$followers, $expected]) {
            $this->assertSame($expected, $this->kol($followers)->level, "followers={$followers}");
        }
    }

    /** Median 7 angka = nilai tengah setelah urut; angka 0 ikut diurutkan, tidak dibuang. */
    public function test_median_views_benar_termasuk_ada_angka_nol(): void
    {
        $kol = $this->kol();

        $s = KolScreening::create([
            'kol_id' => $kol->id, 'tanggal_listing' => '2026-07-01', 'ratecard' => 1_000_000,
            'views_1' => 5000, 'views_2' => 0, 'views_3' => 12000, 'views_4' => 800,
            'views_5' => 0, 'views_6' => 3000, 'views_7' => 700,
        ]);

        // Urut: 0,0,700,800,3000,5000,12000 → tengah = 800.
        $this->assertSame(800, $s->median_views);
        $this->assertSame(21_500, $s->total_views);
        $this->assertEqualsWithDelta(21_500 / 7, $s->rata_views, 0.1);
    }

    /**
     * Tingkatan verdict DARI RUMUS ASLI KOL SKINKU.xlsx (bukan lagi placeholder):
     * Median (kolom V): <60rb Worth It · <120rb Masih Oke · sisanya Kemahalan.
     * Mean (kolom U, rumus terbaru): <10rb Sangat Bagus · <20rb Bagus ·
     * <30rb Dipertimbangkan · <50rb Buruk · sisanya Sangat Buruk.
     */
    public function test_verdict_median_tiga_tingkat_di_batasnya(): void
    {
        $kol = $this->kol(100_000);

        // CPM = ratecard/100rb*1000 -> ratecard 5,9jt = CPM 59rb (Worth, di bawah batas)
        $this->assertSame(KolScreening::VERDICT_WORTH, $this->screen($kol, 5_900_000, 100_000)->verdict_median);
        $this->assertSame(KolScreening::VERDICT_MASIH, $this->screen($kol, 6_000_000, 100_000)->verdict_median);   // 60rb persis -> Masih Oke
        $this->assertSame(KolScreening::VERDICT_MASIH, $this->screen($kol, 11_900_000, 100_000)->verdict_median);  // 119rb
        $this->assertSame(KolScreening::VERDICT_MAHAL, $this->screen($kol, 12_000_000, 100_000)->verdict_median);  // 120rb persis -> Kemahalan

        // Views nol semua -> CPM tak terdefinisi -> tetap Kemahalan, bukan kosong.
        $nol = $this->screen($kol, 1_000_000, 0);
        $this->assertNull($nol->cpm_median);
        $this->assertSame(KolScreening::VERDICT_MAHAL, $nol->verdict_median);
    }

    public function test_verdict_mean_lima_tingkat_di_batasnya(): void
    {
        $kol = $this->kol(100_000);

        $cases = [
            [900_000, '🟢 Sangat Bagus'],       // CPM 9rb
            [1_000_000, '🟡 Bagus'],            // 10rb persis naik tingkat
            [1_900_000, '🟡 Bagus'],
            [2_000_000, '🟠 Dipertimbangkan'],  // 20rb
            [3_000_000, '🔴 Buruk'],            // 30rb
            [5_000_000, '⚫ Sangat Buruk'],     // 50rb
        ];
        foreach ($cases as [$ratecard, $expected]) {
            $this->assertSame($expected, $this->screen($kol, $ratecard, 100_000)->verdict_rata, "ratecard={$ratecard}");
        }
    }

    /**
     * Form screening = satu baris Excel: KOL yang belum ada dibuatkan sekalian.
     * Memaksa "daftarkan dulu, baru screening" berarti input dua kali — persis
     * yang mau dihilangkan dari Excel.
     */
    public function test_screening_username_baru_membuat_kol_sekaligus(): void
    {
        $spec = $this->user('kol_specialist', 'spec2');

        $payload = [
            'tiktok_username' => 'glowupwithfalih', 'followers' => 200_000,
            'kategori' => 'Skinfluencer', 'tanggal_listing' => '2026-07-10', 'ratecard' => 1_000_000,
        ];
        foreach (range(1, 7) as $i) {
            $payload["views_{$i}"] = 50_000;
        }

        $this->actingAs($spec)->post(route('kol-screenings.store'), $payload)->assertRedirect();

        $kol = Kol::where('tiktok_username', 'glowupwithfalih')->first();
        $this->assertNotNull($kol);                       // KOL dibuat otomatis
        $this->assertSame('Middle', $kol->level);
        $this->assertSame(1, $kol->screenings()->count());

        // Halaman detail menampilkan hasil: median, CPM (1jt/50rb×1000 = 20rb), ratio (50rb/200rb = 25%).
        $html = $this->actingAs($spec)->get(route('kols.show', $kol))->assertOk()->getContent();
        $this->assertStringContainsString('50.000', $html);
        $this->assertStringContainsString('20.000', $html);
        $this->assertStringContainsString('25,00%', $html);
        $this->assertStringContainsString('Worth It', $html);
    }

    /** Username lama (termasuk ditulis pakai "@") dipakai ulang, bukan diduplikat. */
    public function test_screening_username_lama_dipakai_ulang_dan_followers_terbarukan(): void
    {
        $spec = $this->user('kol_specialist', 'spec3');
        $kol = Kol::create(['tiktok_username' => 'silvatrasite', 'followers' => 100_000]);

        $payload = [
            'tiktok_username' => '@silvatrasite',   // pakai @ pun tetap orang yang sama
            'followers' => 392_800,                  // angka baru → ratio ikut angka segar
            'tanggal_listing' => '2026-07-10', 'ratecard' => 3_000_000,
        ];
        foreach (range(1, 7) as $i) {
            $payload["views_{$i}"] = 30_000;
        }

        $this->actingAs($spec)->post(route('kol-screenings.store'), $payload)
            ->assertRedirect(route('kols.show', $kol->id));

        $this->assertSame(1, Kol::count());              // TIDAK ada KOL duplikat
        $this->assertSame(392_800, $kol->fresh()->followers);
        $this->assertSame(1, $kol->screenings()->count());
    }

    /** Field opsional yang dikosongkan TIDAK menimpa data lama KOL. */
    public function test_screening_ulang_tanpa_kategori_tidak_menghapus_kategori_lama(): void
    {
        $spec = $this->user('kol_specialist', 'spec4');
        $kol = Kol::create(['tiktok_username' => 'fluffyaa', 'followers' => 1_700_000, 'kategori' => 'Makeup']);

        $payload = [
            'tiktok_username' => 'fluffyaa', 'followers' => 1_700_000,
            'tanggal_listing' => '2026-07-10', 'ratecard' => 6_500_000,
        ];
        foreach (range(1, 7) as $i) {
            $payload["views_{$i}"] = 9_000;
        }

        $this->actingAs($spec)->post(route('kol-screenings.store'), $payload)->assertRedirect();

        $this->assertSame('Makeup', $kol->fresh()->kategori);
    }

    public function test_ratio_dihitung_dari_followers_kol(): void
    {
        $kol = $this->kol(100_000);
        $s = KolScreening::create([
            'kol_id' => $kol->id, 'tanggal_listing' => '2026-07-01', 'ratecard' => 1,
            'views_1' => 4000, 'views_2' => 4000, 'views_3' => 4000, 'views_4' => 4000,
            'views_5' => 4000, 'views_6' => 4000, 'views_7' => 4000,
        ]);

        $this->assertEqualsWithDelta(4.0, $s->ratio, 0.01);   // 4000/100000 = 4%
    }

    /** Screening 7 views seragam supaya CPM gampang dihitung: ratecard/views×1000. */
    private function screen(Kol $kol, int $ratecard, int $views): KolScreening
    {
        $payload = ['kol_id' => $kol->id, 'tanggal_listing' => '2026-07-10', 'ratecard' => $ratecard];
        foreach (range(1, 7) as $i) {
            $payload["views_{$i}"] = $views;
        }

        return KolScreening::create($payload);
    }

    public function test_filter_verdict_menyaring_worth_mahal_dan_belum(): void
    {
        $spec = $this->user('kol_specialist', 'specf');

        $murah = Kol::create(['tiktok_username' => 'murah', 'followers' => 100_000]);
        $this->screen($murah, 1_000_000, 100_000);   // CPM 10rb → worth
        $mahal = Kol::create(['tiktok_username' => 'mahal', 'followers' => 100_000]);
        $this->screen($mahal, 15_000_000, 100_000);   // CPM 150rb -> kemahalan (>=120rb)
        $masih = Kol::create(['tiktok_username' => 'masihoke', 'followers' => 100_000]);
        $this->screen($masih, 10_000_000, 100_000);   // CPM 100rb -> masih oke
        Kol::create(['tiktok_username' => 'polos', 'followers' => 5_000]);   // belum discreening

        $this->actingAs($spec)->get(route('kols.index', ['verdict' => 'worth']))->assertOk()
            ->assertSee('@murah')->assertDontSee('@mahal')->assertDontSee('@polos');

        $this->actingAs($spec)->get(route('kols.index', ['verdict' => 'mahal']))->assertOk()
            ->assertSee('@mahal')->assertDontSee('@murah')->assertDontSee('@masihoke');

        $this->actingAs($spec)->get(route('kols.index', ['verdict' => 'masih']))->assertOk()
            ->assertSee('@masihoke')->assertDontSee('@mahal')->assertDontSee('@murah');

        $this->actingAs($spec)->get(route('kols.index', ['verdict' => 'belum']))->assertOk()
            ->assertSee('@polos')->assertDontSee('@murah')->assertDontSee('@mahal');
    }

    public function test_sort_verdict_urut_cpm_termurah_dulu_dan_belum_screening_selalu_di_bawah(): void
    {
        $spec = $this->user('kol_specialist', 'specs');

        $b = Kol::create(['tiktok_username' => 'bbb', 'followers' => 100_000]);
        $this->screen($b, 3_000_000, 100_000);   // CPM 30rb
        $a = Kol::create(['tiktok_username' => 'aaa', 'followers' => 100_000]);
        $this->screen($a, 1_000_000, 100_000);   // CPM 10rb — termurah
        Kol::create(['tiktok_username' => 'zzz_polos', 'followers' => 5_000]);   // tanpa screening

        $html = $this->actingAs($spec)
            ->get(route('kols.index', ['sort' => 'verdict', 'dir' => 'asc']))->assertOk()->getContent();

        // asc: termurah (aaa) sebelum bbb, dan yang belum discreening paling bawah.
        $this->assertLessThan(strpos($html, '@bbb'), strpos($html, '@aaa'));
        $this->assertGreaterThan(strpos($html, '@bbb'), strpos($html, '@zzz_polos'));

        // desc: bbb dulu — tapi yang belum discreening TETAP di bawah, "tak ada
        // data" bukan berarti paling mahal.
        $html = $this->actingAs($spec)
            ->get(route('kols.index', ['sort' => 'verdict', 'dir' => 'desc']))->assertOk()->getContent();
        $this->assertLessThan(strpos($html, '@aaa'), strpos($html, '@bbb'));
        $this->assertGreaterThan(strpos($html, '@aaa'), strpos($html, '@zzz_polos'));
    }

    public function test_sort_followers_dua_arah(): void
    {
        $spec = $this->user('kol_specialist', 'specr');
        Kol::create(['tiktok_username' => 'kecil', 'followers' => 1_000]);
        Kol::create(['tiktok_username' => 'besar', 'followers' => 2_000_000]);

        $html = $this->actingAs($spec)
            ->get(route('kols.index', ['sort' => 'followers', 'dir' => 'desc']))->assertOk()->getContent();
        $this->assertLessThan(strpos($html, '@kecil'), strpos($html, '@besar'));

        // Nilai sort/dir ngawur tidak meledak — jatuh ke default.
        $this->actingAs($spec)->get(route('kols.index', ['sort' => 'hack', 'dir' => 'up']))->assertOk();
    }

    /**
     * Angka kurasi terakhir tampil DI DAFTAR: ratecard (harga yang diminta),
     * median views, ratio — tanpa harus membuka detail satu-satu. Daftar yang
     * hanya menampilkan verdict membuat orang bertanya "datanya mana?".
     */
    public function test_daftar_kol_menampilkan_ratecard_median_dan_ratio(): void
    {
        $spec = $this->user('kol_specialist', 'specl');
        $kol = Kol::create(['tiktok_username' => 'mulmull', 'followers' => 12_000]);
        $this->screen($kol, 5_000_000, 1_000);   // CPM 5jt — kemahalan

        $html = $this->actingAs($spec)->get(route('kols.index'))->assertOk()->getContent();

        $this->assertStringContainsString('5.000.000', $html);    // ratecard tampil
        $this->assertStringContainsString('1.000', $html);         // median views
        $this->assertStringContainsString('8,3%', $html);          // ratio 1000/12000
        $this->assertStringContainsString('Kemahalan', $html);
        $this->assertStringContainsString('detail →', $html);      // jalan ke rincian jelas
        // 7 views mentah per kolom + total ikut di DAFTAR — tanpa klik ke detail.
        $this->assertStringContainsString('Views 7 Video Terakhir', $html);
        $this->assertStringContainsString('7.000', $html);   // kolom Total
    }

    /**
     * Detail screening menampilkan DATA MENTAHNYA — 7 views satu per satu +
     * total — bukan cuma hasil olahan. Plus dua verdict (median & mean) seperti
     * dua kolom indikator di Excel sumber.
     */
    public function test_detail_menampilkan_7_views_mentah_total_dan_dua_verdict(): void
    {
        $spec = $this->user('kol_specialist', 'specd');
        $kol = Kol::create(['tiktok_username' => 'detailkol', 'followers' => 100_000]);

        KolScreening::create([
            'kol_id' => $kol->id, 'tanggal_listing' => '2026-07-10', 'ratecard' => 1_000_000,
            'views_1' => 105_200, 'views_2' => 6_627, 'views_3' => 1_165, 'views_4' => 131_400,
            'views_5' => 2_874, 'views_6' => 1_040, 'views_7' => 11_000,
        ]);

        $html = $this->actingAs($spec)->get(route('kols.show', $kol))->assertOk()->getContent();

        $this->assertStringContainsString('105.200', $html);   // views mentah tampil
        $this->assertStringContainsString('131.400', $html);
        $this->assertStringContainsString('259.306', $html);   // total views
        $this->assertStringContainsString('Verdict (Median)', $html);
        $this->assertStringContainsString('Verdict (Mean)', $html);
    }

    /**
     * GMV + Viral + Fake — porting rumus Excel kolom W, divalidasi dengan BARIS
     * NYATA dari spreadsheet: followers 6.956, views [105.200, 6.627, 1.165,
     * 131.400, 2.874, 1.040, 11.000] → median 6.627, mean 37.044 → sel Excel
     * menampilkan "GMV 3.021.912 | Viral:High | Fake:Safe".
     */
    public function test_gmv_viral_fake_cocok_dengan_baris_nyata_excel(): void
    {
        $kol = Kol::create(['tiktok_username' => 'dummyexcel', 'followers' => 6_956]);
        $s = KolScreening::create([
            'kol_id' => $kol->id, 'tanggal_listing' => '2026-04-22', 'ratecard' => 55_000,
            'views_1' => 105_200, 'views_2' => 6_627, 'views_3' => 1_165, 'views_4' => 131_400,
            'views_5' => 2_874, 'views_6' => 1_040, 'views_7' => 11_000,
        ]);

        $this->assertSame(6_627, $s->median_views);
        $this->assertSame(3_021_912, $s->gmv_estimate);      // 6627 × 0,012 × 38.000
        $this->assertSame('High', $s->viral_label);           // mean 37.044 ≥ 6.627×2
        $this->assertSame('🟢Safe', $s->fake_label);          // 6.627 ≥ 6.956×5%
    }

    public function test_viral_dan_fake_di_batas_ambang(): void
    {
        // Viral: median 10.000 → High mulai mean 20.000, Mid mulai 13.000.
        $kol = Kol::create(['tiktok_username' => 'ambang', 'followers' => 1_000_000]);

        $mid = KolScreening::create([
            'kol_id' => $kol->id, 'tanggal_listing' => '2026-07-01', 'ratecard' => 1,
            // urut: 1.000×3, 10.000(median), 19.000×3 → total 91.000, mean 13.000 persis
            'views_1' => 1_000, 'views_2' => 1_000, 'views_3' => 1_000, 'views_4' => 10_000,
            'views_5' => 19_000, 'views_6' => 19_000, 'views_7' => 40_000,
        ]);
        $this->assertSame(13_000.0, $mid->rata_views);
        $this->assertSame('Mid', $mid->viral_label);          // tepat di ambang 1.3× → Mid

        // Fake: followers 1jt → Red < 20.000, Watch < 50.000, Safe ≥ 50.000.
        $red = KolScreening::create([
            'kol_id' => $kol->id, 'tanggal_listing' => '2026-07-02', 'ratecard' => 1,
            'views_1' => 19_999, 'views_2' => 19_999, 'views_3' => 19_999, 'views_4' => 19_999,
            'views_5' => 19_999, 'views_6' => 19_999, 'views_7' => 19_999,
        ]);
        $this->assertSame('●Red', $red->fake_label);

        $safe = KolScreening::create([
            'kol_id' => $kol->id, 'tanggal_listing' => '2026-07-03', 'ratecard' => 1,
            'views_1' => 50_000, 'views_2' => 50_000, 'views_3' => 50_000, 'views_4' => 50_000,
            'views_5' => 50_000, 'views_6' => 50_000, 'views_7' => 50_000,
        ]);
        $this->assertSame('🟢Safe', $safe->fake_label);       // tepat 5% → Safe (bukan <)
    }

    /** Deviasi sengaja dari Excel: views nol semua → Low, bukan High. */
    public function test_viral_media_nol_bukan_high(): void
    {
        $kol = Kol::create(['tiktok_username' => 'nolvi', 'followers' => 10_000]);
        $s = KolScreening::create([
            'kol_id' => $kol->id, 'tanggal_listing' => '2026-07-01', 'ratecard' => 1_000,
            'views_1' => 0, 'views_2' => 0, 'views_3' => 0, 'views_4' => 0,
            'views_5' => 0, 'views_6' => 0, 'views_7' => 0,
        ]);

        // Rumus Excel mentah menghasilkan "High" untuk 0 ≥ 0×2 — jelas bukan maksudnya.
        $this->assertSame('Low', $s->viral_label);
        $this->assertSame(0, $s->gmv_estimate);
        $this->assertSame('●Red', $s->fake_label);
    }

    public function test_daftar_menampilkan_kolom_gmv_viral_fake(): void
    {
        $spec = $this->user('kol_specialist', 'specg');
        $kol = Kol::create(['tiktok_username' => 'gmvlist', 'followers' => 6_956]);
        KolScreening::create([
            'kol_id' => $kol->id, 'tanggal_listing' => '2026-07-01', 'ratecard' => 55_000,
            'views_1' => 105_200, 'views_2' => 6_627, 'views_3' => 1_165, 'views_4' => 131_400,
            'views_5' => 2_874, 'views_6' => 1_040, 'views_7' => 11_000,
        ]);

        $html = $this->actingAs($spec)->get(route('kols.index'))->assertOk()->getContent();
        $this->assertStringContainsString('GMV · Viral · Fake', $html);
        $this->assertStringContainsString('3.021.912', $html);
        $this->assertStringContainsString('High', $html);
    }

    /**
     * Halaman "Listing KOL" = replika sheet Excel: satu baris per screening,
     * kolom persis. Divalidasi pakai BARIS NYATA pertama spreadsheet.
     */
    public function test_halaman_listing_replika_excel_dengan_baris_nyata(): void
    {
        $spec = $this->user('kol_specialist', 'specx');
        $kol = Kol::create(['tiktok_username' => 'dummyxx', 'followers' => 6_956, 'agency' => 'OUR GOOD MEDIA']);
        KolScreening::create([
            'kol_id' => $kol->id, 'tanggal_listing' => '2026-04-22', 'ratecard' => 55_000,
            'views_1' => 105_200, 'views_2' => 6_627, 'views_3' => 1_165, 'views_4' => 131_400,
            'views_5' => 2_874, 'views_6' => 1_040, 'views_7' => 11_000,
        ]);

        $html = $this->actingAs($spec)->get(route('kols.listing'))->assertOk()->getContent();

        $this->assertStringContainsString('Views 7 Video Terakhir Tiktok', $html);
        $this->assertStringContainsString('CPM AVG (Mean)', $html);
        $this->assertStringContainsString('Rata-Rata [Median] CPM Indicator', $html);
        $this->assertStringContainsString('GMV + Viral + Fake Detector', $html);
        // Angka baris Excel: total 259.306, mean 37.044, CPM mean 1.485 ->
        // Sangat Bagus; CPM median 8.299 -> Worth It; GMV 3.021.912; agency tampil.
        $this->assertStringContainsString('259.306', $html);
        $this->assertStringContainsString('37.044', $html);
        $this->assertStringContainsString('Sangat Bagus', $html);
        $this->assertStringContainsString('Worth It', $html);
        $this->assertStringContainsString('3.021.912', $html);
        $this->assertStringContainsString('OUR GOOD MEDIA', $html);
    }

    public function test_agency_ikut_tersimpan_lewat_form_screening(): void
    {
        $spec = $this->user('kol_specialist', 'specag');

        $payload = [
            'tiktok_username' => 'agencykol', 'followers' => 50_000, 'agency' => 'CMEDIA',
            'tanggal_listing' => '2026-07-10', 'ratecard' => 500_000,
        ];
        foreach (range(1, 7) as $i) {
            $payload["views_{$i}"] = 10_000;
        }

        $this->actingAs($spec)->post(route('kol-screenings.store'), $payload)->assertRedirect();

        $this->assertSame('CMEDIA', Kol::where('tiktok_username', 'agencykol')->value('agency'));
    }

    /**
     * Rank — porting kolom Z Excel: RANK(CPM Mean; seluruh screening; asc).
     * Rank 1 = termurah; nilai kembar berbagi rank, rank berikutnya melompat.
     */
    public function test_rank_mengikuti_cpm_mean_termurah_dengan_perilaku_rank_excel(): void
    {
        $spec = $this->user('kol_specialist', 'specrk');
        $a = Kol::create(['tiktok_username' => 'ranka', 'followers' => 100_000]);
        $b = Kol::create(['tiktok_username' => 'rankb', 'followers' => 100_000]);
        $c = Kol::create(['tiktok_username' => 'rankc', 'followers' => 100_000]);
        $d = Kol::create(['tiktok_username' => 'rankd', 'followers' => 100_000]);

        $this->screen($a, 1_000_000, 100_000);   // CPM mean 10rb  -> rank 1
        $this->screen($b, 2_000_000, 100_000);   // CPM mean 20rb  -> rank 2 (kembar)
        $this->screen($c, 2_000_000, 100_000);   // CPM mean 20rb  -> rank 2 (kembar)
        $this->screen($d, 3_000_000, 100_000);   // CPM mean 30rb  -> rank 4 (melompat, bukan 3)

        $html = $this->actingAs($spec)->get(route('kols.listing'))->assertOk()->getContent();

        $this->assertStringContainsString('#1', $html);
        $this->assertStringContainsString('#2', $html);
        $this->assertStringContainsString('#4', $html);
        $this->assertStringNotContainsString('#3', $html);   // dilompati karena kembar di rank 2

        // Rank juga tampil di Database KOL (rank milik screening terakhir).
        $this->actingAs($spec)->get(route('kols.index'))->assertOk()->assertSee('#4');
    }

    /** CPV = CPM / 1000 — biaya per satu view. */
    public function test_cpv_terhitung_dan_tampil(): void
    {
        $spec = $this->user('kol_specialist', 'speccv');
        $kol = Kol::create(['tiktok_username' => 'cpvkol', 'followers' => 100_000]);
        $s = $this->screen($kol, 3_000_000, 90_000);   // CPM median 33.333,33 -> CPV 33,33

        $this->assertEqualsWithDelta(33.33, $s->cpv_median, 0.01);
        $this->assertEqualsWithDelta(33.33, $s->cpv_rata, 0.01);

        // Views nol -> CPV null, bukan error bagi-nol.
        $nol = $this->screen($kol, 1_000_000, 0);
        $this->assertNull($nol->cpv_median);

        $this->actingAs($spec)->get(route('kols.index'))->assertOk()->assertSee('CPV');
    }

    /**
     * Regresi rank: kasus @mulmull nyata — satu video meledak (9,8jt views)
     * menyeret CPM MEAN jadi termurah, padahal CPM MEDIAN 5jt (Kemahalan).
     * Rank harus ikut MEDIAN: KOL semacam ini di DASAR peringkat, bukan #1.
     */
    public function test_rank_pakai_median_video_meledak_tak_bisa_curi_rank_satu(): void
    {
        $spec = $this->user('kol_specialist', 'specml');

        $sehat = Kol::create(['tiktok_username' => 'sehatkol', 'followers' => 100_000]);
        $this->screen($sehat, 1_700_000, 109_000);   // CPM median ~15.596 -> murah beneran

        $meledak = Kol::create(['tiktok_username' => 'meledakkol', 'followers' => 12_000]);
        KolScreening::create([
            'kol_id' => $meledak->id, 'tanggal_listing' => '2026-07-10', 'ratecard' => 15_000_000,
            'views_1' => 1_300, 'views_2' => 10_000, 'views_3' => 1_000, 'views_4' => 16_300,
            'views_5' => 9_859_250, 'views_6' => 3_000, 'views_7' => 1_000,
        ]);
        $s = KolScreening::where('kol_id', $meledak->id)->first();
        $this->assertLessThan(15_000, $s->cpm_rata);        // mean-nya memang "murah" palsu
        $this->assertSame(5_000_000.0, $s->cpm_median);     // median jujur: 5jt

        $html = $this->actingAs($spec)->get(route('kols.listing'))->assertOk()->getContent();

        // Rank ikut median: sehat #1, meledak #2 (terakhir) — bukan sebaliknya.
        $posSehat = strpos($html, 'sehatkol');
        $posMeledak = strpos($html, 'meledakkol');
        $this->assertNotFalse($posSehat);
        $this->assertNotFalse($posMeledak);
        // Cek rank di dekat masing-masing baris.
        $rowSehat = substr($html, $posSehat, 3000);
        $rowMeledak = substr($html, $posMeledak, 3000);
        $this->assertStringContainsString('#1', $rowSehat);
        $this->assertStringContainsString('#2', $rowMeledak);
    }

    public function test_cpm_dan_cpv_jadi_kolom_tabel_di_daftar(): void
    {
        $spec = $this->user('kol_specialist', 'speccol');
        $kol = Kol::create(['tiktok_username' => 'kolomkol', 'followers' => 100_000]);
        $this->screen($kol, 1_200_000, 36_000);   // CPM 33.333, CPV 33,3

        $html = $this->actingAs($spec)->get(route('kols.index'))->assertOk()->getContent();

        // Header CPM/CPV kini TAUTAN SORT (seperti Excel), bukan teks mati.
        $this->assertStringContainsString('sort=cpm', $html);
        $this->assertStringContainsString('sort=cpv', $html);
        $this->assertStringContainsString('sort=rank', $html);
        $this->assertStringContainsString('33.333', $html);   // CPM di sel sendiri
        $this->assertStringContainsString('33,3', $html);     // CPV di sel sendiri
    }

    /** Semua kolom angka bisa di-sort dari header — termasuk Rank & GMV. */
    public function test_sort_rank_dan_kolom_angka_lain_dari_header(): void
    {
        $spec = $this->user('kol_specialist', 'specsr');
        $murah = Kol::create(['tiktok_username' => 'aamurah', 'followers' => 100_000]);
        $this->screen($murah, 1_000_000, 100_000);    // CPM 10rb -> rank 1
        $mahal = Kol::create(['tiktok_username' => 'zzmahal', 'followers' => 100_000]);
        $this->screen($mahal, 8_000_000, 100_000);    // CPM 80rb -> rank 2
        Kol::create(['tiktok_username' => 'kosong1', 'followers' => 5_000]);   // belum discreening

        // Sort rank asc: rank 1 dulu; yang belum discreening SELALU di dasar.
        $html = $this->actingAs($spec)->get(route('kols.index', ['sort' => 'rank', 'dir' => 'asc']))->assertOk()->getContent();
        $this->assertLessThan(strpos($html, 'zzmahal'), strpos($html, 'aamurah'));
        $this->assertGreaterThan(strpos($html, 'zzmahal'), strpos($html, 'kosong1'));

        // Sort rank desc: urutan berbalik, tapi yang kosong TETAP di dasar.
        $html = $this->actingAs($spec)->get(route('kols.index', ['sort' => 'rank', 'dir' => 'desc']))->assertOk()->getContent();
        $this->assertLessThan(strpos($html, 'aamurah'), strpos($html, 'zzmahal'));
        $this->assertGreaterThan(strpos($html, 'aamurah'), strpos($html, 'kosong1'));

        // Kolom lain ikut bisa: total views desc — mahal & murah sama 100rb×7,
        // jadi cukup pastikan tidak meledak dan kosong tetap di bawah.
        $html = $this->actingAs($spec)->get(route('kols.index', ['sort' => 'total', 'dir' => 'desc']))->assertOk()->getContent();
        $this->assertGreaterThan(strpos($html, 'aamurah'), strpos($html, 'kosong1'));

        $this->actingAs($spec)->get(route('kols.index', ['sort' => 'gmv', 'dir' => 'desc']))->assertOk();
        $this->actingAs($spec)->get(route('kols.index', ['sort' => 'ratecard', 'dir' => 'asc']))->assertOk();
    }

    /**
     * Ratecard OPSIONAL (permintaan Freddie, deviasi dari Excel "Wajib diisi"):
     * kandidat bisa discreening dari views publik SEBELUM nego harga. Tanpa
     * ratecard: median/ratio/GMV/viral/fake tetap hidup; CPM/CPV/verdict/rank
     * menunggu — verdict netral ⚪, BUKAN Kemahalan.
     */
    public function test_screening_tanpa_ratecard_metrik_views_tetap_hidup(): void
    {
        $spec = $this->user('kol_specialist', 'specnr');

        $payload = [
            'tiktok_username' => 'tanpaharga', 'followers' => 100_000,
            'tanggal_listing' => '2026-07-10',
        ];
        foreach (range(1, 7) as $i) {
            $payload["views_{$i}"] = 50_000;
        }

        $this->actingAs($spec)->post(route('kol-screenings.store'), $payload)->assertRedirect();

        $s = KolScreening::whereHas('kol', fn ($q) => $q->where('tiktok_username', 'tanpaharga'))->first();
        $this->assertNotNull($s);
        $this->assertNull($s->ratecard);
        $this->assertNull($s->cpm_median);
        $this->assertNull($s->cpv_median);
        $this->assertSame(KolScreening::VERDICT_BELUM_HARGA, $s->verdict_median);
        $this->assertSame(KolScreening::VERDICT_BELUM_HARGA, $s->verdict_rata);
        // Metrik berbasis views tetap hidup tanpa harga.
        $this->assertSame(50_000, $s->median_views);
        $this->assertGreaterThan(0, $s->gmv_estimate);
        $this->assertSame('🟢Safe', $s->fake_label);

        // Daftar & listing tak meledak; ratecard tampil "—", rank tak memuatnya.
        $this->actingAs($spec)->get(route('kols.index'))->assertOk()->assertSee('Belum Ada Ratecard');
        $this->actingAs($spec)->get(route('kols.listing'))->assertOk();
    }

    public function test_ratecard_bisa_diisi_belakangan_dan_verdict_langsung_hidup(): void
    {
        $spec = $this->user('kol_specialist', 'specfr');
        $kol = Kol::create(['tiktok_username' => 'negokol', 'followers' => 100_000]);
        $s = KolScreening::create([
            'kol_id' => $kol->id, 'tanggal_listing' => '2026-07-10', 'ratecard' => null,
            'views_1' => 100_000, 'views_2' => 100_000, 'views_3' => 100_000, 'views_4' => 100_000,
            'views_5' => 100_000, 'views_6' => 100_000, 'views_7' => 100_000,
        ]);
        $this->assertSame(KolScreening::VERDICT_BELUM_HARGA, $s->verdict_median);

        // Nego selesai -> isi harga -> verdict hidup, tercatat di Audit Log.
        $this->actingAs($spec)->patch(route('kol-screenings.ratecard', $s), ['ratecard' => 2_000_000])
            ->assertRedirect();

        $s->refresh();
        $this->assertSame(2_000_000, $s->ratecard);
        $this->assertSame(KolScreening::VERDICT_WORTH, $s->verdict_median);   // CPM 20rb < 60rb
        $this->assertNotNull(AuditLog::where('action', 'update_kol_screening_ratecard')->first());
    }

    public function test_filter_verdict_tanpa_harga(): void
    {
        $spec = $this->user('kol_specialist', 'spectf');
        $ada = Kol::create(['tiktok_username' => 'adaharga', 'followers' => 100_000]);
        $this->screen($ada, 1_000_000, 100_000);
        $tanpa = Kol::create(['tiktok_username' => 'tanpaharga2', 'followers' => 100_000]);
        KolScreening::create([
            'kol_id' => $tanpa->id, 'tanggal_listing' => '2026-07-10', 'ratecard' => null,
            'views_1' => 10_000, 'views_2' => 10_000, 'views_3' => 10_000, 'views_4' => 10_000,
            'views_5' => 10_000, 'views_6' => 10_000, 'views_7' => 10_000,
        ]);

        $this->actingAs($spec)->get(route('kols.index', ['verdict' => 'tanpa_harga']))->assertOk()
            ->assertSee('tanpaharga2')->assertDontSee('adaharga');
    }

    /* ---------------- Platform sosial media + tautan profil ---------------- */

    /** URL profil dirakit dari platform + handle; link manual menang; '@' dibersihkan. */
    public function test_profile_url_dirakit_dari_platform_dan_handle(): void
    {
        $tt = Kol::create(['tiktok_username' => 'hiitslauren_', 'platform' => 'tiktok', 'followers' => 100_000]);
        $this->assertSame('https://www.tiktok.com/@hiitslauren_', $tt->profileUrl());

        $ig = Kol::create(['tiktok_username' => 'lisaduga7', 'platform' => 'instagram', 'followers' => 100_000]);
        $this->assertSame('https://www.instagram.com/lisaduga7', $ig->profileUrl());

        $yt = Kol::create(['tiktok_username' => 'creatoryt', 'platform' => 'youtube', 'followers' => 100_000]);
        $this->assertSame('https://www.youtube.com/@creatoryt', $yt->profileUrl());

        // Link manual menang atas rakitan otomatis.
        $manual = Kol::create(['tiktok_username' => 'xmanual', 'platform' => 'tiktok', 'tiktok_link' => 'https://vt.tiktok.com/ABC', 'followers' => 1]);
        $this->assertSame('https://vt.tiktok.com/ABC', $manual->profileUrl());

        // Platform tanpa templat & tanpa link manual → null (username tak jadi tautan luar).
        $lain = Kol::create(['tiktok_username' => 'ylain', 'platform' => 'lainnya', 'followers' => 1]);
        $this->assertNull($lain->profileUrl());

        // '@' di depan handle dibersihkan sebelum dirakit.
        $at = Kol::create(['tiktok_username' => '@withat', 'platform' => 'tiktok', 'followers' => 1]);
        $this->assertSame('https://www.tiktok.com/@withat', $at->profileUrl());
    }

    /** KOL baru default TikTok — username di daftar menaut ke profil TikTok. */
    public function test_username_default_tiktok_menautkan_ke_profil(): void
    {
        $spec = $this->user('kol_specialist', 'specpt');
        Kol::create(['tiktok_username' => 'hiitslauren_', 'followers' => 100_700]);   // platform default 'tiktok'

        $html = $this->actingAs($spec)->get(route('kols.index'))->assertOk()->getContent();
        $this->assertStringContainsString('https://www.tiktok.com/@hiitslauren_', $html);
    }

    public function test_tambah_kol_dengan_platform_instagram_menyimpan_dan_menautkan(): void
    {
        $spec = $this->user('kol_specialist', 'specpf');

        $this->actingAs($spec)->post(route('kols.store'), [
            'tiktok_username' => 'igqueen', 'platform' => 'instagram', 'followers' => 150_000,
        ])->assertRedirect();

        $this->assertSame('instagram', Kol::where('tiktok_username', 'igqueen')->value('platform'));

        $html = $this->actingAs($spec)->get(route('kols.index'))->assertOk()->getContent();
        $this->assertStringContainsString('https://www.instagram.com/igqueen', $html);
        $this->assertStringContainsString('Instagram', $html);
    }

    /** Form screening menyetel platform KOL baru sekaligus. */
    public function test_screening_menyetel_platform_kol_baru(): void
    {
        $spec = $this->user('kol_specialist', 'specpy');

        $payload = [
            'tiktok_username' => 'ytcreator', 'platform' => 'youtube', 'followers' => 300_000,
            'tanggal_listing' => '2026-07-10', 'ratecard' => 1_000_000,
        ];
        foreach (range(1, 7) as $i) {
            $payload["views_{$i}"] = 40_000;
        }

        $this->actingAs($spec)->post(route('kol-screenings.store'), $payload)->assertRedirect();

        $this->assertSame('youtube', Kol::where('tiktok_username', 'ytcreator')->value('platform'));
    }

    /** Platform di luar daftar ditolak — tak bisa nyelipkan nilai ngawur. */
    public function test_platform_ngawur_ditolak(): void
    {
        $spec = $this->user('kol_specialist', 'specpx');

        $this->actingAs($spec)->post(route('kols.store'), [
            'tiktok_username' => 'badplat', 'platform' => 'myspace', 'followers' => 1,
        ])->assertSessionHasErrors('platform');

        $this->assertNull(Kol::where('tiktok_username', 'badplat')->first());
    }
}
