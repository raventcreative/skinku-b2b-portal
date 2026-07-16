<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\ShopeeOrder;
use App\Models\TiktokOrder;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChannelSalesTest extends TestCase
{
    use RefreshDatabase;

    public function test_splits_realized_sales_by_channel(): void
    {
        // PO: 1 completed (dihitung) + 1 pending (diabaikan)
        PurchaseOrder::create(['po_number' => 'PO-1', 'created_by' => 1, 'user_id' => 1, 'status' => 'completed', 'total_amount' => 1_000_000, 'user_role' => 'reseller']);
        PurchaseOrder::create(['po_number' => 'PO-2', 'created_by' => 1, 'user_id' => 1, 'status' => 'pending', 'total_amount' => 9_000_000, 'user_role' => 'reseller']);

        // TikTok: COMPLETED (dihitung) + IN_TRANSIT (diabaikan — belum terealisasi)
        TiktokOrder::create(['tiktok_order_id' => 'T-1', 'status' => 'COMPLETED', 'total_amount' => 600_000, 'line_items' => []]);
        TiktokOrder::create(['tiktok_order_id' => 'T-2', 'status' => 'IN_TRANSIT', 'total_amount' => 5_000_000, 'line_items' => []]);

        // Shopee: COMPLETED
        ShopeeOrder::create(['order_sn' => 'SP-1', 'status' => 'COMPLETED', 'total_amount' => 400_000, 'line_items' => []]);

        $ch = collect(app(ReportService::class)->channelSales())->keyBy('key');

        $this->assertEqualsWithDelta(1_000_000, $ch['reseller']['total'], 0.01);
        $this->assertEqualsWithDelta(600_000, $ch['tiktok']['total'], 0.01);
        $this->assertEqualsWithDelta(400_000, $ch['shopee']['total'], 0.01);
        // total 2jt → proporsi 50/30/20 dihitung di view; di sini pastikan angkanya benar
        $this->assertSame(2_000_000.0, $ch->sum('total'));
    }

    public function test_dashboard_renders_channel_panel_for_staff(): void
    {
        $admin = User::create([
            'name' => 'A', 'fullname' => 'A', 'username' => 'chadm', 'email' => 'ch@skinku.test',
            'password' => Hash::make('secret123'),
            'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);

        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertSee('Penjualan per Channel');
    }
}
