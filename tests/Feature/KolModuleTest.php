<?php

namespace Tests\Feature;

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

    /** Verdict mengikuti ambang config — diuji dengan ambang yang DISETEL di test, bukan tebakan. */
    public function test_verdict_worth_it_di_bawah_ambang_dan_kemahalan_di_atasnya(): void
    {
        config(['kol.cpm_threshold' => 25_000]);
        $kol = $this->kol(100_000);

        // ratecard 2jt, median 100rb views → CPM 20.000 ≤ 25.000 → Worth It.
        $murah = KolScreening::create([
            'kol_id' => $kol->id, 'tanggal_listing' => '2026-07-01', 'ratecard' => 2_000_000,
            'views_1' => 100_000, 'views_2' => 100_000, 'views_3' => 100_000, 'views_4' => 100_000,
            'views_5' => 100_000, 'views_6' => 100_000, 'views_7' => 100_000,
        ]);
        $this->assertEqualsWithDelta(20_000, $murah->cpm_median, 0.01);
        $this->assertSame(KolScreening::VERDICT_WORTH, $murah->verdict_median);

        // ratecard 3jt, median 100rb → CPM 30.000 > 25.000 → Kemahalan.
        $mahal = KolScreening::create([
            'kol_id' => $kol->id, 'tanggal_listing' => '2026-07-02', 'ratecard' => 3_000_000,
            'views_1' => 100_000, 'views_2' => 100_000, 'views_3' => 100_000, 'views_4' => 100_000,
            'views_5' => 100_000, 'views_6' => 100_000, 'views_7' => 100_000,
        ]);
        $this->assertSame(KolScreening::VERDICT_MAHAL, $mahal->verdict_median);

        // Views nol semua → CPM tak terdefinisi → tetap Kemahalan, bukan verdict kosong.
        $nol = KolScreening::create([
            'kol_id' => $kol->id, 'tanggal_listing' => '2026-07-03', 'ratecard' => 1_000_000,
            'views_1' => 0, 'views_2' => 0, 'views_3' => 0, 'views_4' => 0,
            'views_5' => 0, 'views_6' => 0, 'views_7' => 0,
        ]);
        $this->assertNull($nol->cpm_median);
        $this->assertSame(KolScreening::VERDICT_MAHAL, $nol->verdict_median);
    }

    /**
     * Form screening = satu baris Excel: KOL yang belum ada dibuatkan sekalian.
     * Memaksa "daftarkan dulu, baru screening" berarti input dua kali — persis
     * yang mau dihilangkan dari Excel.
     */
    public function test_screening_username_baru_membuat_kol_sekaligus(): void
    {
        config(['kol.cpm_threshold' => 25_000]);
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
        config(['kol.cpm_threshold' => 25_000]);
        $spec = $this->user('kol_specialist', 'specf');

        $murah = Kol::create(['tiktok_username' => 'murah', 'followers' => 100_000]);
        $this->screen($murah, 1_000_000, 100_000);   // CPM 10rb → worth
        $mahal = Kol::create(['tiktok_username' => 'mahal', 'followers' => 100_000]);
        $this->screen($mahal, 5_000_000, 100_000);   // CPM 50rb → kemahalan
        Kol::create(['tiktok_username' => 'polos', 'followers' => 5_000]);   // belum discreening

        $this->actingAs($spec)->get(route('kols.index', ['verdict' => 'worth']))->assertOk()
            ->assertSee('@murah')->assertDontSee('@mahal')->assertDontSee('@polos');

        $this->actingAs($spec)->get(route('kols.index', ['verdict' => 'mahal']))->assertOk()
            ->assertSee('@mahal')->assertDontSee('@murah');

        $this->actingAs($spec)->get(route('kols.index', ['verdict' => 'belum']))->assertOk()
            ->assertSee('@polos')->assertDontSee('@murah')->assertDontSee('@mahal');
    }

    public function test_sort_verdict_urut_cpm_termurah_dulu_dan_belum_screening_selalu_di_bawah(): void
    {
        config(['kol.cpm_threshold' => 25_000]);
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
        config(['kol.cpm_threshold' => 25_000]);
        $spec = $this->user('kol_specialist', 'specl');
        $kol = Kol::create(['tiktok_username' => 'mulmull', 'followers' => 12_000]);
        $this->screen($kol, 5_000_000, 1_000);   // CPM 5jt — kemahalan

        $html = $this->actingAs($spec)->get(route('kols.index'))->assertOk()->getContent();

        $this->assertStringContainsString('5.000.000', $html);    // ratecard tampil
        $this->assertStringContainsString('1.000', $html);         // median views
        $this->assertStringContainsString('8,3%', $html);          // ratio 1000/12000
        $this->assertStringContainsString('Kemahalan', $html);
        $this->assertStringContainsString('detail →', $html);      // jalan ke rincian jelas
    }

    /**
     * Detail screening menampilkan DATA MENTAHNYA — 7 views satu per satu +
     * total — bukan cuma hasil olahan. Plus dua verdict (median & mean) seperti
     * dua kolom indikator di Excel sumber.
     */
    public function test_detail_menampilkan_7_views_mentah_total_dan_dua_verdict(): void
    {
        config(['kol.cpm_threshold' => 25_000]);
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
}
