<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\User;
use App\Services\KolAffiliateService;
use App\Services\KolGapokService;
use App\Services\TikTokAffiliateService;
use App\Services\TikTokClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
