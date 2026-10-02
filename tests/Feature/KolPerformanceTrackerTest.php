<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\KolAffiliateTransaction;
use App\Models\KolDeal;
use App\Models\KolTiktokProfile;
use App\Models\KolTiktokSnapshot;
use App\Models\TiktokAffiliateConnection;
use App\Models\User;
use App\Services\TikTokAffiliateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
            'selection_region' => 'ID',
            'follower_gender' => [['key' => 'male', 'value' => '0.1113'], ['key' => 'female', 'value' => '0.4126']],
            'follower_age' => [['key' => '25-34', 'value' => '0.5888'], ['key' => '18-24', 'value' => '0.2338'], ['key' => '35-44', 'value' => '0.1373']],
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
        // Demografi ikut diperbarui dari endpoint performa.
        $this->assertSame('FEMALE', $m['gender']);
        $this->assertSame(41.3, $m['gender_pct']);
        $this->assertSame('25–34, 18–24', $m['age_ranges']);
        $this->assertSame('ID', $m['region']);
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

    public function test_opsi_semua_ikut_menarik_kol_prospek(): void
    {
        $this->connect();
        Http::fake(['*marketplace_creators/OID*' => Http::response(['code' => 0, 'data' => $this->performanceJson()], 200)]);
        $prospek = $this->kol('prospekall', ['status' => Kol::STATUS_PROSPEK]);

        $this->artisan('tiktok:kol-performance-sync', ['--sleep' => 0, '--semua' => true])->assertSuccessful();

        $this->assertTrue(KolTiktokSnapshot::where('kol_id', $prospek->id)->exists());
    }

    public function test_porsi_skinku_30_hari_dibanding_gmv_asli_dan_periode_bisa_diganti(): void
    {
        $this->travelTo(Carbon::parse('2026-10-01 10:00'));
        $kol = $this->kol('porsikol');
        $kol->tiktokProfile->update(['gmv_idr' => 10_000_000]); // GMV Asli 30 hari, semua brand
        // 2 jt di September (masih dalam 30 hari) + 500rb di Oktober.
        foreach ([['O1', '2026-09-20', 2_000_000], ['O2', '2026-10-01', 500_000]] as [$id, $tgl, $gmv]) {
            KolAffiliateTransaction::create(['platform' => 'tiktok', 'order_id' => $id, 'kol_id' => $kol->id,
                'raw_username' => 'porsikol', 'gmv' => $gmv, 'order_date' => $tgl, 'status' => 'completed']);
        }
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'saporsi');

        // Kolom 30 hari: 2,5 jt ÷ 10 jt = 25%; kolom bulan ini: 500rb — tampil berdampingan.
        $this->actingAs($super)->get(route('kols.index'))->assertOk()
            ->assertSee('Porsi SKINKU')->assertSee('Rp 2.500.000')->assertSee('25,0%')->assertSee('Rp 500.000');

        // GMV Asli TikTok basi (lebih kecil dari GMV SKINKU) → ditandai, bukan 250%.
        $kol->tiktokProfile->update(['gmv_idr' => 1_000_000]);
        $html = $this->actingAs($super)->get(route('kols.index'))->assertOk()->getContent();
        $this->assertStringContainsString('100%+', $html);
        $this->assertStringNotContainsString('250,0%', $html);
    }

    public function test_jadikan_kol_semua_memasukkan_affiliate_belum_cocok_tanpa_dobel(): void
    {
        $ada = Kol::create(['tiktok_username' => 'sudahada', 'followers' => 10]);
        foreach ([['A1', 'Sudahada'], ['A2', 'barukreator'], ['A3', 'barukreator']] as [$id, $u]) {
            KolAffiliateTransaction::create(['platform' => 'tiktok', 'order_id' => $id, 'raw_username' => $u,
                'gmv' => 100_000, 'order_date' => now()->toDateString(), 'status' => 'completed']);
        }

        $this->actingAs($this->user(User::ROLE_GUDANG, 'gudall'))
            ->post(route('kol-affiliate.promote-all'))->assertForbidden();

        $this->actingAs($this->user('kol_specialist', 'specall'))
            ->post(route('kol-affiliate.promote-all'))->assertRedirect();

        $baru = Kol::where('tiktok_username', 'barukreator')->firstOrFail();
        $this->assertSame('affiliate', $baru->role);
        $this->assertSame(1, Kol::where('tiktok_username', 'sudahada')->count()); // tak dobel
        $this->assertSame(2, KolAffiliateTransaction::where('kol_id', $baru->id)->count());
        $this->assertSame(1, KolAffiliateTransaction::where('kol_id', $ada->id)->count());
        $this->assertSame(0, KolAffiliateTransaction::whereNull('kol_id')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'promote_all_affiliates_to_kol']);
    }

    public function test_database_kol_ada_nomor_urut_dan_total(): void
    {
        Kol::create(['tiktok_username' => 'satu', 'followers' => 1]);
        Kol::create(['tiktok_username' => 'dua', 'followers' => 1, 'status' => Kol::STATUS_AKTIF]);
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'sano');

        $this->actingAs($super)->get(route('kols.index'))->assertOk()->assertSee('Total: 2 KOL');
        $this->actingAs($super)->get(route('kols.index', ['status' => Kol::STATUS_AKTIF]))->assertOk()
            ->assertSee('Total: 1 KOL (sesuai filter)');
    }

    public function test_tag_kol_hanya_untuk_yang_sudah_punya_deal(): void
    {
        $tanpaDeal = Kol::create(['tiktok_username' => 'prospekbaru', 'followers' => 1, 'role' => 'kol']);
        $deal = Kol::create(['tiktok_username' => 'sudahdeal', 'followers' => 1, 'role' => 'kol']);
        $batal = Kol::create(['tiktok_username' => 'dealbatal', 'followers' => 1, 'role' => 'kol']);
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'satag');
        foreach ([[$deal, 'berjalan'], [$batal, 'batal']] as [$k, $st]) {
            KolDeal::create(['kode' => 'D'.$k->id, 'kol_id' => $k->id, 'jenis' => 'vt', 'total_biaya' => 1_000_000,
                'status' => $st, 'periode_mulai' => now()->toDateString()]);
        }
        Kol::create(['tiktok_username' => 'afil', 'followers' => 1, 'role' => 'affiliate']);

        $html = $this->actingAs($super)->get(route('kols.index'))
            ->assertOk()->assertSee('Database KOL / Affiliate')->getContent();
        $this->assertSame(1, substr_count($html, 'title="Sudah punya deal KOL">KOL</span>')); // hanya @sudahdeal
        $this->assertSame(1, substr_count($html, 'text-sky-700">Affiliate</span>'));
    }

    public function test_kolom_tiktok_bisa_diurutkan_dan_status_sync_dibedakan(): void
    {
        $a = $this->kol('gpmkecil');
        $a->tiktokProfile->update(['gpm_idr' => 1_000]);
        $b = $this->kol('gpmbesar');
        $b->tiktokProfile->update(['gpm_idr' => 50_000]);
        Kol::create(['tiktok_username' => 'belumdicek', 'followers' => 0]);
        Kol::create(['tiktok_username' => 'takada', 'followers' => 0, 'tiktok_checked_at' => now()]);
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'sasort');

        $html = $this->actingAs($super)->get(route('kols.index', ['sort' => 'gpm', 'dir' => 'desc']))->assertOk()->getContent();
        $this->assertTrue(strpos($html, '@gpmbesar') < strpos($html, '@gpmkecil'));
        $this->assertTrue(strpos($html, '@gpmkecil') < strpos($html, '@belumdicek')); // tanpa data → bawah
        $this->assertStringContainsString('antre sync', $html);
        $this->assertStringContainsString('tak ada di TikTok', $html);
    }
}
