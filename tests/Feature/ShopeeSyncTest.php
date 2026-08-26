<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ShopeeConnection;
use App\Models\ShopeeOrder;
use App\Models\ShopeeSkuMap;
use App\Models\User;
use App\Services\ShopeeClient;
use App\Services\ShopeeOrderService;
use App\Services\ShopeeSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ShopeeSyncTest extends TestCase
{
    use RefreshDatabase;

    /** Fake client: tak memanggil API asli, balikin data kaleng. */
    private function fakeClient(array $list, array $detail): ShopeeClient
    {
        return new class($list, $detail) extends ShopeeClient
        {
            /** Direkam supaya test bisa memeriksa window waktu ($from) yang beneran dikirim. */
            public ?int $lastFrom = null;

            public function __construct(private array $list, private array $detail) {}

            public function refreshToken(string $refreshToken, string $shopId): array
            {
                return ['access_token' => 'FRESH', 'refresh_token' => 'R2', 'expire_in' => 14400];
            }

            public function getOrderList(string $a, string $s, int $f, int $t, string $cursor = '', int $p = 50): array
            {
                $this->lastFrom = $f;

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

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin Sync', 'fullname' => 'Admin Sync', 'username' => 'shopeesyncadmin',
            'email' => 'shopeesyncadmin@skinku.test', 'password' => Hash::make('secret123'),
            'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'name' => 'Produk Shopee Sync', 'sku' => 'PSS-1', 'hq_stock' => 100, 'status' => 'active',
            'cogs' => 50000, 'price_distributor' => 1, 'price_reseller' => 1,
        ]);
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

    public function test_window_sync_clamp_ke_floor_14_hari_saat_last_synced_basi(): void
    {
        // last_synced_at basi 30 hari (cron mati lama). Tanpa clamp, $from jadi
        // ~30 hari lalu → Shopee tolak (batas 15 hari) → last_synced_at tak pernah
        // maju → wedge permanen. Dengan clamp, $from harus tetap ≥ floor 14 hari.
        $list = ['response' => ['order_list' => [], 'more' => false, 'next_cursor' => '']];
        $client = $this->fakeClient($list, ['response' => ['order_list' => []]]);
        $this->app->instance(ShopeeClient::class, $client);

        $conn = $this->conn(['last_synced_at' => now()->subDays(30)]);
        // Diambil SEBELUM panggilan → batas bawah pasti (anti-flaky), bukan dihitung
        // ulang setelah panggilan (yang bisa geser beberapa detik).
        $floor = now()->subDays(14)->timestamp;

        app(ShopeeSyncService::class)->syncOrders($conn);

        $this->assertNotNull($client->lastFrom);
        $this->assertGreaterThanOrEqual($floor, $client->lastFrom);
    }

    public function test_order_batal_pakai_nilai_item_saat_total_amount_nol(): void
    {
        // Shopee kasih total_amount 0 utk order batal → sistem hitung dari item
        // (harga diskon × qty) biar "batal" tetap kelihatan nilainya (spt TikTok).
        app(ShopeeOrderService::class)->store([[
            'order_sn' => 'CANCEL1', 'order_status' => 'CANCELLED', 'total_amount' => 0,
            'create_time' => now()->timestamp,
            'item_list' => [
                ['item_sku' => 'X', 'item_name' => 'X', 'model_quantity_purchased' => 2, 'model_discounted_price' => 15000],
            ],
        ]]);

        $this->assertEqualsWithDelta(30000, (float) ShopeeOrder::where('order_sn', 'CANCEL1')->value('total_amount'), 0.01);
    }

    public function test_order_normal_tetap_pakai_total_amount_shopee(): void
    {
        // Order normal: total_amount Shopee (sudah termasuk ongkir/diskon) dipakai,
        // BUKAN subtotal item.
        app(ShopeeOrderService::class)->store([[
            'order_sn' => 'OK1', 'order_status' => 'COMPLETED', 'total_amount' => 77665,
            'create_time' => now()->timestamp,
            'item_list' => [
                ['item_sku' => 'X', 'model_quantity_purchased' => 1, 'model_discounted_price' => 50000],
            ],
        ]]);

        $this->assertEqualsWithDelta(77665, (float) ShopeeOrder::where('order_sn', 'OK1')->value('total_amount'), 0.01);
    }

    public function test_backfill_iterasi_window_dan_simpan_histori(): void
    {
        // Fake: tiap window (dibedakan by $from) balikin 1 order unik → memastikan
        // backfill benar-benar iterasi banyak window 14-harian, bukan cuma 1 tarikan.
        $client = new class extends ShopeeClient
        {
            public array $windows = [];

            public function __construct() {}

            public function refreshToken(string $refreshToken, string $shopId): array
            {
                return ['access_token' => 'FRESH', 'refresh_token' => 'R2', 'expire_in' => 14400];
            }

            public function getOrderList(string $a, string $s, int $f, int $t, string $cursor = '', int $p = 50): array
            {
                $this->windows[] = $f;

                return ['response' => ['order_list' => [['order_sn' => 'W'.$f]], 'more' => false, 'next_cursor' => '']];
            }

            public function getOrderDetail(string $a, string $s, array $sns): array
            {
                $orders = [];
                foreach ($sns as $sn) {
                    $orders[] = ['order_sn' => $sn, 'order_status' => 'COMPLETED', 'total_amount' => 10000, 'create_time' => now()->timestamp, 'item_list' => []];
                }

                return ['response' => ['order_list' => $orders]];
            }
        };
        $this->app->instance(ShopeeClient::class, $client);

        $r = app(ShopeeSyncService::class)->backfillOrders($this->conn(), now()->subDays(30), now());

        // 30 hari / 14 → 3 window → 3 order unik ditarik & disimpan.
        $this->assertCount(3, $client->windows);
        $this->assertSame(3, $r['pulled']);
        $this->assertSame(3, $r['stored']);
        $this->assertSame(3, ShopeeOrder::count());
    }

    public function test_auto_deduct_saat_sync_benar_benar_potong_stok(): void
    {
        $p = $this->product();
        ShopeeSkuMap::create(['shopee_sku' => 'SKU-X', 'product_id' => $p->id, 'qty' => 1]);
        $admin = $this->admin();

        $list = ['response' => ['order_list' => [['order_sn' => 'AD1']], 'more' => false, 'next_cursor' => '']];
        $detail = ['response' => ['order_list' => [[
            'order_sn' => 'AD1', 'order_status' => 'SHIPPED', 'total_amount' => 90000,
            'create_time' => now()->timestamp,
            'item_list' => [
                ['item_sku' => 'SKU-X', 'item_name' => 'Produk X', 'quantity_purchased' => 2],
            ],
        ]]]];
        $this->app->instance(ShopeeClient::class, $this->fakeClient($list, $detail));

        $conn = $this->conn(['auto_deduct' => true]);
        app(ShopeeSyncService::class)->syncOrders($conn, $admin->id);

        $order = ShopeeOrder::where('order_sn', 'AD1')->first();
        $this->assertSame(ShopeeOrder::STATUS_DEDUCTED, $order->stock_status);
        $this->assertEquals(98, $p->fresh()->hq_stock); // 100 − 2 (qty map 1 × qty order 2)
    }
}
