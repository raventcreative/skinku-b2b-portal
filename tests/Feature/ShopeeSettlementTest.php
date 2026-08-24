<?php

namespace Tests\Feature;

use App\Models\ShopeeConnection;
use App\Models\ShopeeSettlement;
use App\Models\User;
use App\Services\ShopeeClient;
use App\Services\ShopeeSettlementService;
use App\Services\ShopeeSyncService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopeeSettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_simpan_dan_hitung_fee_total(): void
    {
        $s = ShopeeSettlement::create([
            'order_sn' => 'S-1', 'currency' => 'IDR',
            'escrow_amount' => 64675, 'buyer_total_amount' => 77665,
            'commission_fee' => 1000, 'service_fee' => 500, 'campaign_fee' => 0,
            'seller_transaction_fee' => 0, 'actual_shipping_fee' => 11765,
            'buyer_paid_shipping_fee' => 11765, 'shopee_shipping_rebate' => 0,
            'escrow_tax' => 0, 'withholding_tax' => 0, 'total_adjustment_amount' => 0,
            'posting_status' => ShopeeSettlement::POST_PENDING,
        ]);

        $this->assertFalse($s->isPosted());
        $this->assertEquals('64675.00', $s->fresh()->escrow_amount);
        $this->assertEquals(1500.0, $s->feeTotal()); // commission + service + campaign + seller_txn + tax
    }

    public function test_client_escrow_list_kirim_path_dan_sign(): void
    {
        config(['services.shopee.partner_id' => '123', 'services.shopee.partner_key' => 'secret',
            'services.shopee.api_base' => 'https://partner.example.com']);
        Http::fake(['*get_escrow_list*' => Http::response(['response' => ['escrow_list' => [], 'more' => false]])]);

        app(ShopeeClient::class)->getEscrowList('ACCESS', 'SHOP', 100, 200, 1, 100);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/v2/payment/get_escrow_list')
            && str_contains($r->url(), 'release_time_from=100') && str_contains($r->url(), 'sign='));
    }

    public function test_store_peta_income_dari_escrow_detail(): void
    {
        $svc = app(ShopeeSettlementService::class);
        // bentuk = elemen response get_escrow_detail_batch (order_sn + order_income + escrow_release_time gabungan)
        $detail = [
            'order_sn' => '2608247FYHUBMG',
            'escrow_release_time' => now()->timestamp,
            'order_income' => [
                'escrow_amount' => 64675, 'buyer_total_amount' => 77665,
                'commission_fee' => 0, 'service_fee' => 0, 'campaign_fee' => 0,
                'seller_transaction_fee' => 0, 'actual_shipping_fee' => 11765,
                'buyer_paid_shipping_fee' => 11765, 'shopee_shipping_rebate' => 0,
                'escrow_tax' => 0, 'withholding_tax' => 0, 'total_adjustment_amount' => 0,
            ],
        ];

        $n = $svc->store([$detail]);

        $this->assertSame(1, $n);
        $row = ShopeeSettlement::where('order_sn', '2608247FYHUBMG')->first();
        $this->assertEquals('64675.00', $row->escrow_amount);
        $this->assertEquals('11765.00', $row->actual_shipping_fee);
        $this->assertSame(ShopeeSettlement::POST_PENDING, $row->posting_status);
        $this->assertNotNull($row->escrow_release_time);
        $this->assertIsArray($row->raw);

        // idempoten: posting_status tak reset kalau sudah posted
        $row->update(['posting_status' => ShopeeSettlement::POST_POSTED]);
        $svc->store([$detail]);
        $this->assertSame(ShopeeSettlement::POST_POSTED, $row->fresh()->posting_status);
    }

    public function test_syncsettlements_dari_client_fake(): void
    {
        $client = new class extends ShopeeClient
        {
            public function __construct() {}

            public function getEscrowList(string $a, string $s, int $f, int $t, int $p = 1, int $ps = 100): array
            {
                return ['response' => ['escrow_list' => [
                    ['order_sn' => 'S-9', 'escrow_release_time' => 1787000000, 'payout_amount' => 64675],
                ], 'more' => false]];
            }

            public function getEscrowDetailBatch(string $a, string $s, array $sns): array
            {
                return ['response' => [
                    ['order_sn' => 'S-9', 'order_income' => ['escrow_amount' => 64675, 'buyer_total_amount' => 77665, 'actual_shipping_fee' => 11765]],
                ]];
            }
        };
        $this->app->instance(ShopeeClient::class, $client);

        $conn = ShopeeConnection::create(['shop_id' => '9', 'access_token' => 'A', 'refresh_token' => 'R',
            'access_expires_at' => now()->addHours(3), 'refresh_expires_at' => now()->addDays(30)]);

        $r = app(ShopeeSyncService::class)->syncSettlements($conn);

        $this->assertSame(1, $r['count']);
        $row = ShopeeSettlement::where('order_sn', 'S-9')->first();
        $this->assertEquals('64675.00', $row->escrow_amount);
        $this->assertNotNull($row->escrow_release_time); // digabung dari escrow_list
    }

    public function test_cron_menjadwalkan_shopee_sync_settlements(): void
    {
        $found = collect(app(Schedule::class)->events())
            ->contains(fn ($e) => str_contains($e->command ?? '', 'shopee:sync --settlements'));
        $this->assertTrue($found, 'shopee:sync --settlements harus terjadwal');
    }

    private function admin(): User
    {
        return User::create(['name' => 'A', 'fullname' => 'A', 'username' => 'setadmin',
            'email' => 'setadmin@skinku.test', 'password' => Hash::make('secret123'),
            'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE]);
    }

    public function test_halaman_pencairan_render_dan_reseller_ditolak(): void
    {
        ShopeeSettlement::create(['order_sn' => 'S-2', 'escrow_amount' => 100, 'buyer_total_amount' => 120,
            'posting_status' => ShopeeSettlement::POST_PENDING]);

        $this->actingAs($this->admin())->get('/shopee/settlements')->assertOk();

        $reseller = User::create(['name' => 'R', 'fullname' => 'R', 'username' => 'res_set',
            'email' => 'res_set@skinku.test', 'password' => Hash::make('secret123'),
            'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE]);
        $this->actingAs($reseller)->get('/shopee/settlements')->assertForbidden();
    }
}
