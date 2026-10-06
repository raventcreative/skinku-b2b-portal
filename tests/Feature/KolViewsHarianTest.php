<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\KolContentDailySnapshot;
use App\Models\TiktokAffiliateConnection;
use App\Models\User;
use App\Services\KolViewsHarianService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Report views harian video SKINKU: potret harian disimpan saat sync konten
 * affiliate, views tanggal D = potret (D+1) − potret sebelumnya.
 */
class KolViewsHarianTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function snap(Kol $k, string $id, string $period, string $tgl, int $views, ?string $posted = '2026-09-01', int $gmv = 0): void
    {
        KolContentDailySnapshot::create(['kol_id' => $k->id, 'content_id' => $id, 'period' => $period,
            'captured_on' => $tgl, 'posted_at' => $posted, 'views' => $views, 'gmv' => $gmv]);
    }

    public function test_sync_konten_menyimpan_potret_harian_tanpa_dobel(): void
    {
        TiktokAffiliateConnection::create(['shop_id' => 'S1', 'shop_cipher' => 'C', 'access_token' => 'tok', 'refresh_token' => 'ref',
            'access_expires_at' => now()->addDays(5), 'refresh_expires_at' => now()->addDays(30)]);
        $kol = Kol::create(['tiktok_username' => 'kreatorx', 'followers' => 1]);
        Http::fake([
            '*shop_videos/performance*' => Http::response(['code' => 0, 'data' => ['videos' => [[
                'id' => 'V1', 'username' => 'kreatorx', 'title' => 'Review scrub', 'views' => 1200,
                'gmv' => ['amount' => '150000'], 'items_sold' => 3, 'video_post_time' => '2026-10-01 10:00:00',
            ]]]], 200),
            '*shop_lives/performance*' => Http::response(['code' => 0, 'data' => ['live_stream_sessions' => []]], 200),
        ]);

        $this->artisan('tiktok:affiliate-content-sync')->assertSuccessful();
        $this->artisan('tiktok:affiliate-content-sync')->assertSuccessful(); // dua kali sehari → tetap 1 baris

        $s = KolContentDailySnapshot::where('kol_id', $kol->id)->sole();
        $this->assertSame('V1', $s->content_id);
        $this->assertSame(1200, $s->views);
        $this->assertSame(now()->toDateString(), (string) $s->captured_on);
    }

    public function test_views_harian_dari_selisih_potret_video_baru_dan_ganti_bulan(): void
    {
        $k = Kol::create(['tiktok_username' => 'harian', 'followers' => 1]);
        // A: video lama. Potret 1 Okt sudah tercatat (ada C) tapi A belum muncul → kemarin = 0, jadi potret 2 Okt
        // (100) seluruhnya views 1 Okt; 3 Okt 250; 4 Okt 400 → views 2 Okt 150, 3 Okt 150.
        $this->snap($k, 'A', '2026-10-01', '2026-10-02', 100);
        $this->snap($k, 'A', '2026-10-01', '2026-10-03', 250);
        $this->snap($k, 'A', '2026-10-01', '2026-10-04', 400);
        // B: diposting 3 Okt, pertama terpotret 4 Okt 80 → dihitung penuh di 3 Okt.
        $this->snap($k, 'B', '2026-10-01', '2026-10-04', 80, '2026-10-03 09:00:00');
        // C: ganti bulan — potret 1 Okt masih period Sep (500), 2 Okt period Okt (30) → views 1 Okt = 30.
        $this->snap($k, 'C', '2026-09-01', '2026-10-01', 500);
        $this->snap($k, 'C', '2026-10-01', '2026-10-02', 30);
        // D: muncul 2 Okt (90, sama spt A → views 1 Okt), lalu koreksi TikTok (turun ke 70) → 0, bukan negatif.
        $this->snap($k, 'D', '2026-10-01', '2026-10-02', 90);
        $this->snap($k, 'D', '2026-10-01', '2026-10-03', 70);

        $rep = app(KolViewsHarianService::class)->report(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-03'));
        $row = $rep['rows']->sole();

        // 1 Okt = A 100 + C 30 + D 90; 2 Okt = A 150 + D 0; 3 Okt = A 150 + B 80.
        $this->assertSame(['2026-10-01' => 220, '2026-10-02' => 150, '2026-10-03' => 230], $row['views']);
        $this->assertSame(600, $row['total_views']);
        $this->assertSame(600, array_sum($rep['totals']));
    }

    public function test_video_lama_aktif_lagi_saat_kemarin_tercatat_dihitung_penuh_hari_pertamanya(): void
    {
        $k = Kol::create(['tiktok_username' => 'aktiflagi', 'followers' => 1]);
        // X dipotret tiap pagi (pencatatan berjalan). Y = video lama (Agustus) yang baru dapat views lagi 2 Sep:
        // tak ada di potret 2 Sep (= 0 views bulan ini), muncul di potret 3 Sep dgn 500 → seluruhnya views 2 Sep.
        $this->snap($k, 'X', '2026-09-01', '2026-09-01', 10, '2026-08-01');
        $this->snap($k, 'X', '2026-09-01', '2026-09-02', 20, '2026-08-01');
        $this->snap($k, 'X', '2026-09-01', '2026-09-03', 30, '2026-08-01');
        $this->snap($k, 'Y', '2026-09-01', '2026-09-03', 500, '2026-08-01');

        $row = app(KolViewsHarianService::class)->report(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-02'))['rows']->sole();

        $this->assertSame(['2026-09-01' => 10, '2026-09-02' => 510], $row['views']);
        $this->assertSame(2, $row['videos']);
    }

    public function test_hari_yang_belum_dipotret_tetap_titik_awal_agar_tak_menggelembung(): void
    {
        $k = Kol::create(['tiktok_username' => 'celah', 'followers' => 1]);
        // Sync 4 Sep (potret hari 3 Sep) gagal total → tak ada potret 3 Sep utk video mana pun. Y (video lama) baru
        // muncul di potret 4 Sep: kemarinnya tak tercatat → tak tahu berapa yg lama, jadi hanya titik awal.
        $this->snap($k, 'X', '2026-09-01', '2026-09-01', 10, '2026-08-01');
        $this->snap($k, 'X', '2026-09-01', '2026-09-02', 20, '2026-08-01');
        $this->snap($k, 'X', '2026-09-01', '2026-09-04', 40, '2026-08-01');
        $this->snap($k, 'Y', '2026-09-01', '2026-09-04', 300, '2026-08-01');

        $row = app(KolViewsHarianService::class)->report(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-03'))['rows']->sole();

        // X lintas celah: selisih 2 hari masuk ke 3 Sep (perilaku lama). Y tak dihitung.
        $this->assertSame(['2026-09-01' => 10, '2026-09-02' => 0, '2026-09-03' => 20], $row['views']);
        $this->assertSame(1, $row['videos']);
    }

    public function test_halaman_dan_export_butuh_izin_affiliate(): void
    {
        $k = Kol::create(['tiktok_username' => 'tampil', 'followers' => 1]);
        $this->snap($k, 'A', now()->startOfMonth()->toDateString(), now()->subDays(2)->toDateString(), 100);
        $this->snap($k, 'A', now()->startOfMonth()->toDateString(), now()->subDay()->toDateString(), 175);

        $this->actingAs($this->user(User::ROLE_GUDANG, 'gudvh'))->get(route('kol-views-harian.index'))->assertForbidden();

        $super = $this->user(User::ROLE_SUPER_ADMIN, 'savh');
        $this->actingAs($super)->get(route('kol-views-harian.index'))->assertOk()
            ->assertSee('Views Harian Video SKINKU')->assertSee('@tampil')->assertSee('75');
        $this->actingAs($super)->get(route('kol-views-harian.export'))->assertOk()
            ->assertHeader('content-disposition');
    }
}
