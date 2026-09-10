<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\ShopeeOrder;
use App\Models\TiktokOrder;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SalesTrendByChannelTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create([
            'name' => $role, 'fullname' => strtoupper($role), 'username' => $role,
            'email' => $role.'@skinku.test', 'password' => Hash::make('secret123'),
            'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function seedData(): void
    {
        $day = Carbon::today()->toDateString();
        $admin = $this->user(User::ROLE_ADMIN);

        // PO completed HQ-langsung (seller_id null) — masuk garis Reseller/PO.
        PurchaseOrder::create([
            'po_number' => 'PO-TREND-1', 'created_by' => $admin->id, 'user_id' => $admin->id,
            'status' => PurchaseOrder::STATUS_COMPLETED, 'total_amount' => 100_000, 'user_role' => 'reseller',
            'order_date' => $day, 'completed_at' => $day.' 10:00:00',
        ]);

        TiktokOrder::create([
            'tiktok_order_id' => 'TT-1', 'status' => 'DELIVERED', 'total_amount' => 50_000,
            'currency' => 'IDR', 'line_items' => [], 'stock_status' => TiktokOrder::STATUS_PENDING,
            'order_created_at' => $day.' 09:00:00',
        ]);

        ShopeeOrder::create([
            'order_sn' => 'SP-1', 'status' => 'COMPLETED', 'total_amount' => 30_000,
            'currency' => 'IDR', 'line_items' => [], 'stock_status' => ShopeeOrder::STATUS_PENDING,
            'order_created_at' => $day.' 08:00:00',
        ]);
    }

    public function test_data_per_channel_benar(): void
    {
        $this->seedData();

        $report = app(ReportService::class)->salesTrendByChannel(Carbon::now());

        $this->assertNotEmpty($report['labels']);
        $byKey = collect($report['channels'])->keyBy('key');

        $this->assertSame(100000.0, array_sum($byKey['reseller']['data']));
        $this->assertSame(50000.0, array_sum($byKey['tiktok']['data']));
        $this->assertSame(30000.0, array_sum($byKey['shopee']['data']));
        // Panjang tiap deret = jumlah label (selaras sumbu-x).
        $this->assertCount(count($report['labels']), $byKey['tiktok']['data']);
    }

    public function test_staff_dapat_trend_channel_mitra_tidak(): void
    {
        $this->seedData();
        $staff = $this->user(User::ROLE_SUPER_ADMIN);
        $mitra = $this->user(User::ROLE_RESELLER);

        // Staff/HQ dapat data per-channel.
        $this->assertNotNull(
            $this->actingAs($staff)->get('/dashboard')->assertOk()->viewData('trendByChannel')
        );

        // Mitra TIDAK — supaya data channel marketplace tak bocor.
        $this->assertNull(
            $this->actingAs($mitra)->get('/dashboard')->assertOk()->viewData('trendByChannel')
        );
    }
}
