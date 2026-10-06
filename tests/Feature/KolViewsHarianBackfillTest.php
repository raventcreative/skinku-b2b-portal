<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\KolContentDailySnapshot;
use App\Models\TiktokAffiliateConnection;
use App\Services\KolViewsHarianService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Isi mundur views harian (tiktok:affiliate-views-backfill): potret pagi yang terlewat disimulasikan dgn meminta
 * data kumulatif bulan s/d hari sebelumnya (start_date_ge = awal bulan, end_date_lt = tanggal potret), lalu report
 * yang sudah ada menghitung views hariannya. Default simulasi; potret asli tak pernah ditimpa.
 */
class KolViewsHarianBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-06 10:00:00');
        TiktokAffiliateConnection::create(['shop_id' => 'S1', 'shop_cipher' => 'C', 'access_token' => 'tok', 'refresh_token' => 'ref',
            'access_expires_at' => now()->addDays(5), 'refresh_expires_at' => now()->addDays(30)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Query string request analytics → [start_date_ge, end_date_lt]. */
    private function rentang(Request $r): array
    {
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

        return [$q['start_date_ge'] ?? null, $q['end_date_lt'] ?? null];
    }

    /**
     * Fake API: video V1 milik $username, views kumulatif = 100 × jumlah hari dalam rentang (naik 100/hari).
     * $gagalPada = end_date_lt yg dijawab galat (simulasi riwayat TikTok tak tersedia).
     */
    private function fakeApi(string $username = 'kreatorx', ?string $gagalPada = null): void
    {
        Http::fake(['*shop_videos/performance*' => function (Request $r) use ($username, $gagalPada) {
            [$start, $end] = $this->rentang($r);
            if ($end === $gagalPada) {
                return Http::response(['code' => 36009004, 'message' => 'date out of range'], 200);
            }
            $hari = Carbon::parse($start)->diffInDays(Carbon::parse($end));

            return Http::response(['code' => 0, 'data' => ['videos' => [[
                'id' => 'V1', 'username' => $username, 'title' => 'Review scrub', 'views' => 100 * $hari,
                'gmv' => ['amount' => (string) (1000 * $hari)], 'items_sold' => 0, 'video_post_time' => '2026-08-01 10:00:00',
            ]]]], 200);
        }]);
    }

    public function test_default_simulasi_tak_menulis_apa_pun(): void
    {
        Kol::create(['tiktok_username' => 'kreatorx', 'followers' => 1]);
        $this->fakeApi();

        $this->artisan('tiktok:affiliate-views-backfill', ['--dari' => '2026-09-28', '--sampai' => '2026-09-30'])
            ->expectsOutputToContain('SIMULASI')
            ->assertSuccessful();

        $this->assertSame(0, KolContentDailySnapshot::count());
    }

    public function test_simpan_mengisi_potret_dan_report_menghitung_views_harian(): void
    {
        $kol = Kol::create(['tiktok_username' => 'kreatorx', 'followers' => 1]);
        $this->fakeApi();

        $this->artisan('tiktok:affiliate-views-backfill', ['--dari' => '2026-09-28', '--sampai' => '2026-09-30', '--simpan' => true])
            ->assertSuccessful();

        // Potret 28 Sep s/d 1 Okt (hari terakhir butuh potret keesokan paginya).
        $this->assertSame(['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01'],
            KolContentDailySnapshot::orderBy('captured_on')->pluck('captured_on')->map(fn ($d) => Carbon::parse($d)->toDateString())->all());

        $rep = app(KolViewsHarianService::class)->report(Carbon::parse('2026-09-28'), Carbon::parse('2026-09-30'));
        $row = $rep['rows']->firstWhere(fn ($r) => $r['kol']->id === $kol->id);
        $this->assertSame(['2026-09-28' => 100, '2026-09-29' => 100, '2026-09-30' => 100], $row['views']);
        $this->assertSame(3000, $row['total_gmv']);
    }

    public function test_potret_tanggal_1_memakai_periode_bulan_lalu(): void
    {
        Kol::create(['tiktok_username' => 'kreatorx', 'followers' => 1]);
        $this->fakeApi();

        $this->artisan('tiktok:affiliate-views-backfill', ['--dari' => '2026-09-30', '--sampai' => '2026-09-30', '--simpan' => true])
            ->assertSuccessful();

        Http::assertSent(fn (Request $r) => $this->rentang($r) === ['2026-09-01', '2026-10-01']);
        $okt1 = KolContentDailySnapshot::whereDate('captured_on', '2026-10-01')->sole();
        $this->assertSame('2026-09-01', Carbon::parse($okt1->period)->toDateString());
        $this->assertSame(3000, $okt1->views); // 1–30 Sep
    }

    public function test_potret_asli_tak_pernah_ditimpa(): void
    {
        $kol = Kol::create(['tiktok_username' => 'kreatorx', 'followers' => 1]);
        KolContentDailySnapshot::create(['kol_id' => $kol->id, 'content_id' => 'V1', 'period' => '2026-09-01',
            'captured_on' => '2026-09-29', 'views' => 99999, 'gmv' => 0]);
        $this->fakeApi();

        $this->artisan('tiktok:affiliate-views-backfill', ['--dari' => '2026-09-28', '--sampai' => '2026-09-29', '--simpan' => true])
            ->expectsOutputToContain('2026-09-29: potret asli sudah ada')
            ->assertSuccessful();

        $this->assertSame(99999, KolContentDailySnapshot::whereDate('captured_on', '2026-09-29')->sole()->views);
        Http::assertNotSent(fn (Request $r) => $this->rentang($r)[1] === '2026-09-29');
    }

    public function test_default_sampai_berhenti_sebelum_potret_asli_pertama(): void
    {
        $kol = Kol::create(['tiktok_username' => 'kreatorx', 'followers' => 1]);
        KolContentDailySnapshot::create(['kol_id' => $kol->id, 'content_id' => 'V1', 'period' => '2026-10-01',
            'captured_on' => '2026-10-03', 'views' => 200, 'gmv' => 0]);
        $this->fakeApi();

        $this->artisan('tiktok:affiliate-views-backfill', ['--dari' => '2026-10-01', '--simpan' => true])->assertSuccessful();

        // Hari 1–2 Okt → potret 1, 2 (baru) + 3 Okt (asli, dilewati).
        $this->assertSame(['2026-10-01', '2026-10-02', '2026-10-03'],
            KolContentDailySnapshot::orderBy('captured_on')->pluck('captured_on')->map(fn ($d) => Carbon::parse($d)->toDateString())->all());
        $this->assertSame(200, KolContentDailySnapshot::whereDate('captured_on', '2026-10-03')->sole()->views);
    }

    public function test_kreator_bukan_kol_tak_disimpan(): void
    {
        $this->fakeApi('bukankol');

        $this->artisan('tiktok:affiliate-views-backfill', ['--dari' => '2026-09-28', '--sampai' => '2026-09-28', '--simpan' => true])
            ->assertSuccessful();

        $this->assertSame(0, KolContentDailySnapshot::count());
    }

    public function test_galat_api_satu_tanggal_dilaporkan_tanggal_lain_tetap_jalan(): void
    {
        Kol::create(['tiktok_username' => 'kreatorx', 'followers' => 1]);
        $this->fakeApi('kreatorx', '2026-09-28'); // potret 28 Sep: riwayat tak tersedia

        $this->artisan('tiktok:affiliate-views-backfill', ['--dari' => '2026-09-28', '--sampai' => '2026-09-29', '--simpan' => true])
            ->expectsOutputToContain('✗ 2026-09-28')
            ->assertFailed();

        $this->assertSame(['2026-09-29', '2026-09-30'],
            KolContentDailySnapshot::orderBy('captured_on')->pluck('captured_on')->map(fn ($d) => Carbon::parse($d)->toDateString())->all());
    }

    public function test_tanpa_dari_ditolak(): void
    {
        $this->artisan('tiktok:affiliate-views-backfill')->assertFailed();
    }
}
