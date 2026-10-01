<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\KolTiktokProfile;
use App\Models\KolTiktokSnapshot;
use App\Models\TiktokAffiliateConnection;
use App\Models\User;
use App\Services\TikTokAffiliateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tracker performa TikTok KOL: endpoint marketplace_creators/{open_id} → profil
 * (angka terbaru) + snapshot per tanggal (grafik). Uang selalu Rupiah.
 */
class KolPerformanceTrackerTest extends TestCase
{
    use RefreshDatabase;

    /** Potongan respons ASLI dari probe (kreator yhuri333). */
    private function performanceJson(): array
    {
        return ['creator' => [
            'avg_commission_rate' => 700,
            'avg_ec_live_view_count' => 198,
            'avg_ec_video_play_count' => 800,
            'brand_collaboration_count' => 12,
            'ec_live_count' => 11,
            'ec_live_engagement_rate' => '8774',
            'ec_video_count' => 47,
            'ec_video_engagement_rate' => '250',
            'follower_count' => 2459,
            'gmv' => ['amount' => '151.745473', 'currency' => 'USD'],
            'gmv_range' => ['currency' => 'USD', 'formatted_range' => 'Rp1JT+'],
            'gpm' => ['amount' => '1.697', 'currency' => 'USD'],
            'live_gmv' => ['amount' => '12.192392', 'currency' => 'USD'],
            'units_sold' => 29,
            'username' => 'yhuri333',
            'video_gmv' => ['amount' => '126.671118', 'currency' => 'USD'],
        ]];
    }

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function connect(): void
    {
        TiktokAffiliateConnection::create([
            'shop_id' => 'S1', 'shop_cipher' => 'CIPHER', 'access_token' => 'tok', 'refresh_token' => 'ref',
            'access_expires_at' => now()->addDays(5), 'refresh_expires_at' => now()->addDays(30),
        ]);
    }

    private function kol(string $u, array $attrs = [], ?string $openId = 'OID'): Kol
    {
        $kol = Kol::create(['tiktok_username' => $u, 'followers' => 0] + $attrs);
        KolTiktokProfile::create(['kol_id' => $kol->id, 'open_id' => $openId]);

        return $kol;
    }

    public function test_mapping_performa_semua_uang_rupiah_dan_persen_basis_10000(): void
    {
        config(['services.tiktok_affiliate.usd_idr_rate' => 16000]);
        $m = app(TikTokAffiliateService::class)->mapCreatorPerformance($this->performanceJson());

        $this->assertSame(2_427_928, $m['gmv_idr']);        // 151,745473 × 16.000
        $this->assertSame(2_026_738, $m['video_gmv_idr']);
        $this->assertSame(195_078, $m['live_gmv_idr']);
        $this->assertSame(27_152, $m['gpm_idr']);           // 1,697 × 16.000 per 1.000 views
        $this->assertSame(2.5, $m['video_engagement_pct']); // 250 / 100
        $this->assertSame(87.74, $m['live_engagement_pct']);
        $this->assertSame(7.0, $m['avg_commission_pct']);
        $this->assertSame(800, $m['avg_video_views']);
        $this->assertSame(47, $m['video_count']);
        $this->assertSame(2459, $m['followers']);
        $this->assertSame('Rp1JT+', $m['gmv_range']);
    }

    public function test_sync_mingguan_hanya_kol_aktif_dan_simpan_snapshot(): void
    {
        $this->connect();
        Http::fake(['*marketplace_creators/OID*' => Http::response(['code' => 0, 'data' => $this->performanceJson()], 200)]);
        $aktif = $this->kol('aktifkol', ['status' => Kol::STATUS_AKTIF]);
        $prospek = $this->kol('prospekkol', ['status' => Kol::STATUS_PROSPEK]);
        $tanpaOpenId = $this->kol('tanpaid', ['status' => Kol::STATUS_AKTIF], null);

        $this->artisan('tiktok:kol-performance-sync', ['--sleep' => 0])->assertSuccessful();

        $snap = KolTiktokSnapshot::where('kol_id', $aktif->id)->firstOrFail();
        $this->assertSame(800, $snap->avg_video_views);
        $this->assertSame(2.5, $snap->video_engagement_pct);
        $this->assertNotNull($aktif->fresh()->tiktokProfile->performance_synced_at);
        $this->assertSame(2459, $aktif->fresh()->followers);
        $this->assertFalse(KolTiktokSnapshot::where('kol_id', $prospek->id)->exists());
        $this->assertFalse(KolTiktokSnapshot::where('kol_id', $tanpaOpenId->id)->exists());

        // Sync kedua di hari yang sama tak menggandakan titik grafik; stale-days melewatinya.
        $this->artisan('tiktok:kol-performance-sync', ['--sleep' => 0, '--stale-days' => 0])->assertSuccessful();
        $this->assertSame(1, KolTiktokSnapshot::where('kol_id', $aktif->id)->count());
    }

    public function test_kena_rate_limit_berhenti_tanpa_simpan(): void
    {
        $this->connect();
        Http::fake(['*marketplace_creators/*' => Http::response(['code' => 36009002, 'message' => 'Too many requests'], 200)]);
        $kol = $this->kol('limitkol', ['status' => Kol::STATUS_AKTIF]);

        $this->artisan('tiktok:kol-performance-sync', ['--sleep' => 0])->assertSuccessful();

        $this->assertFalse(KolTiktokSnapshot::exists());
        $this->assertNull($kol->fresh()->tiktokProfile->performance_synced_at);
    }

    public function test_tombol_perbarui_per_kol_butuh_izin_manage_dan_detail_tampil_rupiah(): void
    {
        $this->connect();
        Http::fake(['*marketplace_creators/OID*' => Http::response(['code' => 0, 'data' => $this->performanceJson()], 200)]);
        $kol = $this->kol('detailkol');
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'sakol');

        $this->actingAs($this->user(User::ROLE_GUDANG, 'gudkol'))
            ->post(route('kols.tiktok-performance', $kol))->assertForbidden();

        $this->actingAs($super)->post(route('kols.tiktok-performance', $kol))->assertRedirect();
        $this->assertTrue(KolTiktokSnapshot::where('kol_id', $kol->id)->exists());

        $html = $this->actingAs($super)->get(route('kols.show', $kol))->assertOk()->getContent();
        $this->assertStringContainsString('GPM (per 1.000 views)', $html);
        $this->assertStringNotContainsString('$151', $html); // tak ada dolar di layar

        $this->actingAs($super)->get(route('kols.index'))->assertOk()->assertSee('GPM')->assertSee('2,5%');
    }
}
