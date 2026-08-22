<?php

namespace Tests\Feature;

use App\Models\ShopeeConnection;
use App\Models\ShopeeOrder;
use App\Services\ShopeeClient;
use App\Services\ShopeeSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopeeSyncTest extends TestCase
{
    use RefreshDatabase;

    /** Fake client: tak memanggil API asli, balikin data kaleng. */
    private function fakeClient(array $list, array $detail): ShopeeClient
    {
        return new class($list, $detail) extends ShopeeClient
        {
            public function __construct(private array $list, private array $detail) {}

            public function refreshToken(string $refreshToken, string $shopId): array
            {
                return ['access_token' => 'FRESH', 'refresh_token' => 'R2', 'expire_in' => 14400];
            }

            public function getOrderList(string $a, string $s, int $f, int $t, string $cursor = '', int $p = 50): array
            {
                return $this->list;
            }

            public function getOrderDetail(string $a, string $s, array $sns): array
            {
                return $this->detail;
            }
        };
    }

    private function conn(array $extra = []): ShopeeConnection
    {
        return ShopeeConnection::create(array_merge([
            'shop_id' => '999', 'access_token' => 'OLD', 'refresh_token' => 'R1',
            'access_expires_at' => now()->addHours(3), 'refresh_expires_at' => now()->addDays(30),
        ], $extra));
    }

    public function test_sync_menyimpan_order(): void
    {
        // list → response.order_list[].order_sn ; detail → response.order_list[] (dgn item)
        $list = ['response' => ['order_list' => [['order_sn' => 'S1']], 'more' => false, 'next_cursor' => '']];
        $detail = ['response' => ['order_list' => [[
            'order_sn' => 'S1', 'order_status' => 'COMPLETED', 'total_amount' => 50000,
            'create_time' => now()->timestamp, 'item_list' => [],
        ]]]];
        $this->app->instance(ShopeeClient::class, $this->fakeClient($list, $detail));

        $sync = app(ShopeeSyncService::class);
        $r = $sync->syncOrders($this->conn());

        $this->assertSame(1, $r['count']);
        $this->assertSame('COMPLETED', ShopeeOrder::where('order_sn', 'S1')->value('status'));
    }

    public function test_freshtoken_refresh_saat_hampir_kadaluarsa(): void
    {
        $this->app->instance(ShopeeClient::class, $this->fakeClient([], []));
        $conn = $this->conn(['access_expires_at' => now()->addMinutes(2)]); // < ambang → refresh
        $token = app(ShopeeSyncService::class)->freshToken($conn);
        $this->assertSame('FRESH', $token);
        $this->assertSame('FRESH', $conn->fresh()->access_token);
    }
}
