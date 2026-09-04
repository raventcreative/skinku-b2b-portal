<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\KolScreening;
use App\Models\KolTiktokProfile;
use App\Models\User;
use App\Services\KolAffiliateService;
use App\Services\KolGapokService;
use App\Services\TikTokAffiliateService;
use App\Services\TikTokClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TikTokAffiliateTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_halaman_render_dan_gate_manage_tiktok(): void
    {
        // gudang tak punya manage_tiktok → forbidden
        $this->actingAs($this->user(User::ROLE_GUDANG, 'gd'))->get(route('tiktok-affiliate.index'))->assertForbidden();

        // admin → OK
        $this->actingAs($this->user(User::ROLE_ADMIN, 'adm'))->get(route('tiktok-affiliate.index'))
            ->assertOk()->assertSee('TikTok Affiliate API');
    }

    public function test_client_memakai_kredensial_config_key(): void
    {
        config([
            'services.tiktok.app_key' => 'shopkey', 'services.tiktok.app_secret' => 'shopsecret',
            'services.tiktok_affiliate.app_key' => 'affkey', 'services.tiktok_affiliate.app_secret' => 'affsecret',
        ]);
        $shop = new TikTokClient('tiktok');
        $aff = new TikTokClient('tiktok_affiliate');

        $this->assertTrue($aff->configured());
        // Tanda tangan beda (app_secret beda) → bukti client pakai kredensial affiliate.
        $q = ['app_key' => 'affkey', 'timestamp' => '123'];
        $this->assertNotSame($shop->sign('/x', $q), $aff->sign('/x', $q));
    }

    public function test_probe_tanpa_koneksi_ditolak(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN, 'adm2'))
            ->post(route('tiktok-affiliate.probe'))->assertStatus(400);
    }

    /** Kunci parser ke bentuk respons ASLI TikTok (dari probe). */
    public function test_map_orders_dari_respons_asli_tiktok(): void
    {
        $json = <<<'JSON'
        {
          "next_page_token": "abc",
          "total_count": 3,
          "orders": [
            { "create_time": 1788510544, "id": "585887086808040846", "skus": [
              { "content_type": "VIDEO", "creator_username": "dewick02",
                "estimated_commission_base": {"amount": "39900", "currency": "IDR"},
                "estimated_paid_commission": {"amount": "3392", "currency": "IDR"},
                "price": {"amount": "39900", "currency": "IDR"}, "quantity": 1,
                "product_id": "1735591701567080362", "settlement_status": "AWAITING PAYMENT",
                "sku_id": "1736331394090370986" } ] },
            { "create_time": 1788509868, "id": "585886967897097947", "skus": [
              { "content_type": "VIDEO", "creator_username": "ainnisaja",
                "estimated_commission_base": {"amount": "34086", "currency": "IDR"},
                "estimated_paid_commission": {"amount": "3409", "currency": "IDR"},
                "price": {"amount": "34086", "currency": "IDR"}, "quantity": 1,
                "sku_id": "1734056024555227050" } ] },
            { "create_time": 1788509857, "id": "585886965643314350", "skus": [
              { "content_type": "VIDEO", "creator_username": "dewick02",
                "estimated_commission_base": {"amount": "53900", "currency": "IDR"},
                "estimated_paid_commission": [],
                "price": {"amount": "53900", "currency": "IDR"}, "quantity": 1,
                "sku_id": "1736331394090370986" } ] }
          ]
        }
        JSON;

        $rows = app(TikTokAffiliateService::class)->mapOrders(json_decode($json, true));

        $this->assertCount(3, $rows);
        $this->assertSame('dewick02', $rows[0]['username']);
        $this->assertSame('VIDEO', $rows[0]['content_type']);
        $this->assertSame(39900, $rows[0]['gmv']);        // estimated_commission_base
        $this->assertSame(3392, $rows[0]['commission']);  // estimated_paid_commission
        $this->assertSame(1, $rows[0]['qty']);
        $this->assertStringContainsString('585887086808040846', $rows[0]['order_id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $rows[0]['order_date']);

        $this->assertSame('ainnisaja', $rows[1]['username']);
        $this->assertSame(34086, $rows[1]['gmv']);
        $this->assertSame(3409, $rows[1]['commission']);

        // estimated_paid_commission KOSONG ([]) → komisi 0, GMV tetap kebaca.
        $this->assertSame(53900, $rows[2]['gmv']);
        $this->assertSame(0, $rows[2]['commission']);
    }

    /** Kunci normalizer marketplace ke bentuk respons ASLI TikTok (dari probe). */
    public function test_map_marketplace_creator_dari_respons_asli(): void
    {
        $json = <<<'JSON'
        {
          "creators": [
            { "creator_open_id": "3BzdFgAAAACfdiPXWgIvTgqReBLsIyJ9QYKEGiotoQXIyzNws1pXJw",
              "username": "dewick02", "nickname": "D E W I C K",
              "avatar": {"url": "https://cdn/x.webp"},
              "follower_count": 5342130, "selection_region": "ID",
              "avg_ec_live_uv": 4075, "avg_ec_video_view_count": 10980,
              "gmv": {"amount": "124795.841805", "currency": "USD"},
              "gmv_range": {"currency": "USD", "formatted_range": "Rp1JT+", "minimum_amount": "10000"},
              "live_gmv": {"amount": "29069.218463", "currency": "USD"},
              "video_gmv": {"amount": "92273.284029", "currency": "USD"},
              "top_follower_demographics": {
                "age_ranges": ["AGE_RANGE_25_34", "AGE_RANGE_18_24"],
                "major_gender": {"gender": "FEMALE", "percentage": 4694}
              } },
            { "creator_open_id": "vw9XZQ", "username": "dewick021", "nickname": "dewick_",
              "follower_count": 1164, "selection_region": "ID",
              "gmv_range": {"formatted_range": "Rp1JT+"} }
          ]
        }
        JSON;

        $c = app(TikTokAffiliateService::class)->mapMarketplaceCreator(json_decode($json, true)['creators'][0]);

        $this->assertSame('3BzdFgAAAACfdiPXWgIvTgqReBLsIyJ9QYKEGiotoQXIyzNws1pXJw', $c['open_id']);
        $this->assertSame('dewick02', $c['username']);
        $this->assertSame('D E W I C K', $c['nickname']);
        $this->assertSame(5342130, $c['followers']);
        $this->assertEqualsWithDelta(124795.841805, $c['gmv_usd'], 0.001);
        $this->assertSame('Rp1JT+', $c['gmv_range']);
        $this->assertEqualsWithDelta(92273.284029, $c['video_gmv_usd'], 0.001);
        $this->assertEqualsWithDelta(29069.218463, $c['live_gmv_usd'], 0.001);
        $this->assertSame(10980, $c['avg_video_views']);
        $this->assertSame(4075, $c['avg_live_uv']);
        $this->assertSame('ID', $c['region']);
        $this->assertSame('FEMALE', $c['gender']);
        $this->assertSame(46.9, $c['gender_pct']); // 4694 / 100
        $this->assertSame(['AGE_RANGE_25_34', 'AGE_RANGE_18_24'], $c['age_ranges']);

        // Kreator berdata tipis: cuma punya gmv_range (tanpa angka gmv) → USD null.
        $c2 = app(TikTokAffiliateService::class)->mapMarketplaceCreator(json_decode($json, true)['creators'][1]);
        $this->assertNull($c2['gmv_usd']);
        $this->assertNull($c2['video_gmv_usd']);
        $this->assertSame('Rp1JT+', $c2['gmv_range']);
    }

    public function test_halaman_cek_tiktok_render_dan_gate(): void
    {
        // gudang tak punya kol.affiliate.view → forbidden
        $this->actingAs($this->user(User::ROLE_GUDANG, 'gd3'))->get(route('kol-cek-tiktok.index'))->assertForbidden();

        // kol_specialist → OK; tanpa ?q tak ada panggilan API, cuma render form + notis belum terhubung.
        $this->actingAs($this->user('kol_specialist', 'sp9'))->get(route('kol-cek-tiktok.index'))
            ->assertOk()->assertSee('Cek Performa TikTok')->assertSee('belum terhubung');
    }

    public function test_simpan_performa_tiktok_isi_follower_dan_gmv_asli(): void
    {
        config(['services.tiktok_affiliate.usd_idr_rate' => 16000]);
        $kol = Kol::create(['tiktok_username' => 'nidaawafa', 'followers' => 0]);
        KolScreening::create(['kol_id' => $kol->id, 'tanggal_listing' => '2026-09-01', 'ratecard' => 100_000, 'gmv' => null]);

        $this->actingAs($this->user('kol_specialist', 'sp10'))
            ->post(route('kol-cek-tiktok.save'), ['username' => 'nidaawafa', 'followers' => 620_000, 'gmv_usd' => 1000])
            ->assertRedirect();

        $kol->refresh();
        $this->assertSame(620_000, $kol->followers);
        $this->assertSame(16_000_000, (int) $kol->latestScreening()->first()->gmv); // 1000 USD × 16.000
    }

    public function test_simpan_snapshot_lengkap_dari_cache(): void
    {
        config(['services.tiktok_affiliate.usd_idr_rate' => 16000]);
        $kol = Kol::create(['tiktok_username' => 'dewick02', 'followers' => 0]);

        // Seed cache seolah kartu baru dicari (bentuk = hasil mapMarketplaceCreator).
        Cache::put('tt_mkt:'.md5('dewick02'), [[
            'open_id' => 'OPEN123', 'username' => 'dewick02', 'nickname' => 'D E W I C K', 'avatar' => '',
            'followers' => 5_342_130, 'gmv_usd' => 1000.0, 'gmv_range' => 'Rp1JT+',
            'video_gmv_usd' => 800.0, 'live_gmv_usd' => 200.0,
            'avg_video_views' => 10_980, 'avg_live_uv' => 4075, 'region' => 'ID',
            'gender' => 'FEMALE', 'gender_pct' => 46.9, 'age_ranges' => ['AGE_RANGE_25_34', 'AGE_RANGE_18_24'],
        ]], now()->addMinutes(10));

        $this->actingAs($this->user('kol_specialist', 'sp12'))
            ->post(route('kol-cek-tiktok.save'), ['username' => 'dewick02', 'q' => 'dewick02', 'open_id' => 'OPEN123'])
            ->assertRedirect();

        $tp = KolTiktokProfile::where('kol_id', $kol->id)->first();
        $this->assertNotNull($tp);
        $this->assertSame(5_342_130, $tp->followers);
        $this->assertSame(16_000_000, $tp->gmv_idr);        // 1000 USD × 16.000
        $this->assertSame(12_800_000, $tp->video_gmv_idr);  // 800 × 16.000
        $this->assertSame(3_200_000, $tp->live_gmv_idr);    // 200 × 16.000
        $this->assertSame(10_980, $tp->avg_video_views);
        $this->assertSame('FEMALE', $tp->gender);
        $this->assertSame('25–34, 18–24', $tp->age_ranges);
        $this->assertSame(5_342_130, $kol->fresh()->followers); // kols.followers ikut ter-update
    }

    public function test_simpan_performa_username_asing_tak_error(): void
    {
        // Username belum ada di database → redirect dgn pesan error, bukan 500.
        $this->actingAs($this->user('kol_specialist', 'sp11'))
            ->post(route('kol-cek-tiktok.save'), ['username' => 'belumada99', 'followers' => 100])
            ->assertRedirect();
        $this->assertDatabaseMissing('kols', ['tiktok_username' => 'belumada99']);
    }

    /** End-to-end: respons API → parser → import → muncul di Tim Gapok. */
    public function test_sync_map_mengalir_ke_tim_gapok(): void
    {
        $kol = Kol::create(['tiktok_username' => 'dewick02', 'followers' => 5_000_000, 'is_gapok' => true]);
        $ts = now()->timestamp;
        $data = ['orders' => [
            ['id' => 'O1', 'create_time' => $ts, 'skus' => [[
                'creator_username' => 'dewick02', 'content_type' => 'VIDEO', 'quantity' => 1, 'sku_id' => 'S1',
                'estimated_commission_base' => ['amount' => '100000', 'currency' => 'IDR'],
                'estimated_paid_commission' => ['amount' => '8500', 'currency' => 'IDR'],
            ]]],
            ['id' => 'O2', 'create_time' => $ts, 'skus' => [[
                'creator_username' => 'dewick02', 'content_type' => 'LIVE', 'quantity' => 2, 'sku_id' => 'S2',
                'estimated_commission_base' => ['amount' => '50000', 'currency' => 'IDR'],
                'estimated_paid_commission' => [], // kosong → 0
            ]]],
        ]];

        $rows = app(TikTokAffiliateService::class)->mapOrders($data);
        app(KolAffiliateService::class)->import($rows, 'tiktok', null, 'tiktok_api');

        $g = app(KolGapokService::class)->monthly(now())->first(fn ($r) => $r['kol']->id === $kol->id);
        $this->assertNotNull($g);
        $this->assertSame(150_000, $g['gmv']);         // 100rb + 50rb
        $this->assertSame(8_500, $g['commission']);    // 8500 + 0
        $this->assertSame(100_000, $g['gmv_video']);   // split content_type
        $this->assertSame(50_000, $g['gmv_live']);
    }
}
