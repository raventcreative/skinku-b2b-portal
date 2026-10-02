<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\KolScore;
use App\Models\KolScreening;
use App\Models\KolTiktokProfile;
use App\Models\User;
use App\Services\KolScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * KSS setengah otomatis (isian dari screening + data TikTok + riwayat deal),
 * filter/urut APS & KSS di Database KOL, dan tombol (?) penjelasan.
 */
class KolKssOtomatisTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function kolLengkap(): Kol
    {
        $kol = Kol::create(['tiktok_username' => 'lengkap', 'followers' => 100_000, 'kategori' => 'Skinfluencer']);
        KolScreening::create(['kol_id' => $kol->id, 'tanggal_listing' => '2026-09-01', 'ratecard' => 1_500_000]);
        KolTiktokProfile::create(['kol_id' => $kol->id, 'avg_video_views' => 12_000, 'video_engagement_pct' => 3.4,
            'video_count' => 10, 'live_count' => 2, 'performance_synced_at' => now()]);

        return $kol->load(['latestScreening', 'tiktokProfile', 'deals']);
    }

    public function test_prefill_mengisi_dari_screening_tiktok_kategori_dan_deal(): void
    {
        $p = app(KolScoringService::class)->kssPrefill($this->kolLengkap());

        $this->assertSame(1_500_000, $p['rate']);
        $this->assertSame(12_000, $p['median']);            // screening tanpa views → avg views TikTok
        $this->assertSame(3.4, $p['er']);
        $this->assertSame('beauty_majority', $p['niche']);  // Skinfluencer
        $this->assertSame('none', $p['history']);           // belum ada deal
        $this->assertSame('active', $p['readiness']);       // 12 video/LIVE jualan
        $this->assertArrayHasKey('median', $p['sumber']);
    }

    public function test_prefill_kosong_bila_data_tidak_ada(): void
    {
        $kol = Kol::create(['tiktok_username' => 'kosong', 'followers' => 0])->load(['latestScreening', 'tiktokProfile', 'deals']);
        $p = app(KolScoringService::class)->kssPrefill($kol);

        $this->assertNull($p['rate']);
        $this->assertNull($p['median']);
        $this->assertNull($p['er']);
        $this->assertNull($p['niche']);
        $this->assertNull($p['readiness']);
    }

    public function test_tombol_hitung_membuka_kalkulator_dengan_kol_terpilih(): void
    {
        $kol = $this->kolLengkap();
        $html = $this->actingAs($this->user(User::ROLE_SUPER_ADMIN, 'sakss'))
            ->get(route('kol-skor.kss', ['kol' => $kol->id]))->assertOk()->getContent();

        $this->assertStringContainsString('value="@lengkap"', $html);           // combo terpilih
        $this->assertStringContainsString("prefill('{$kol->id}')", $html);       // isi otomatis jalan
        $this->assertStringContainsString('hint-kss', $html);                    // penjelasan (?)
    }

    public function test_database_kol_filter_dan_urut_kss_serta_tombol_hint(): void
    {
        $a = Kol::create(['tiktok_username' => 'kssbagus', 'followers' => 1]);
        $b = Kol::create(['tiktok_username' => 'kssjelek', 'followers' => 1]);
        Kol::create(['tiktok_username' => 'kssbelum', 'followers' => 1]);
        KolScore::create(['kol_id' => $a->id, 'type' => 'kss', 'score' => 82, 'label' => 'shortlist', 'captured_on' => now()]);
        KolScore::create(['kol_id' => $b->id, 'type' => 'kss', 'score' => 31, 'label' => 'tolak', 'captured_on' => now()]);
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'safilter');

        $this->actingAs($super)->get(route('kols.index', ['kss' => 'shortlist']))->assertOk()
            ->assertSee('@kssbagus')->assertDontSee('@kssjelek')->assertDontSee('@kssbelum');
        $this->actingAs($super)->get(route('kols.index', ['kss' => 'belum']))->assertOk()
            ->assertSee('@kssbelum')->assertDontSee('@kssbagus');

        $html = $this->actingAs($super)->get(route('kols.index', ['sort' => 'kss', 'dir' => 'desc']))->assertOk()->getContent();
        $this->assertTrue(strpos($html, '@kssbagus') < strpos($html, '@kssjelek'));
        $this->assertTrue(strpos($html, '@kssjelek') < strpos($html, '@kssbelum')); // belum dihitung → bawah
        $this->assertStringContainsString("kolHint('aps', this)", $html);
        $this->assertStringContainsString('id="hint-gpm"', $html);
        $belum = Kol::where('tiktok_username', 'kssbelum')->value('id');
        $this->assertStringContainsString(e(route('kol-skor.kss', ['kol' => $belum])), $html); // link "hitung"
    }
}
