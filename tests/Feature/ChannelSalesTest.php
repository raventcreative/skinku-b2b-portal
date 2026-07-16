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

class ChannelSalesTest extends TestCase
{
    use RefreshDatabase;

    private function po(string $sn, string $status, float $amount, string $at): void
    {
        $po = PurchaseOrder::create([
            'po_number' => $sn, 'created_by' => 1, 'user_id' => 1,
            'status' => $status, 'total_amount' => $amount, 'user_role' => 'reseller',
        ]);
        // created_at diisi otomatis — paksa ke tanggal uji
        PurchaseOrder::where('id', $po->id)->update(['created_at' => $at]);
    }

    public function test_splits_confirmed_vs_pipeline_per_channel(): void
    {
        Carbon::setTestNow('2026-07-16 12:00:00');

        // --- PO ---
        $this->po('PO-1', 'completed', 1_000_000, '2026-07-05');   // terealisasi
        $this->po('PO-2', 'processing', 500_000, '2026-07-06');    // berjalan
        $this->po('PO-3', 'draft', 9_000_000, '2026-07-07');       // draft → diabaikan
        $this->po('PO-4', 'cancelled', 9_000_000, '2026-07-08');   // batal → diabaikan
        $this->po('PO-5', 'completed', 7_000_000, '2026-06-20');   // bulan lalu → diabaikan

        // --- TikTok ---
        TiktokOrder::create(['tiktok_order_id' => 'T-1', 'status' => 'COMPLETED', 'total_amount' => 600_000, 'order_created_at' => '2026-07-10', 'line_items' => []]);
        TiktokOrder::create(['tiktok_order_id' => 'T-2', 'status' => 'IN_TRANSIT', 'total_amount' => 300_000, 'order_created_at' => '2026-07-11', 'line_items' => []]);
        TiktokOrder::create(['tiktok_order_id' => 'T-3', 'status' => 'UNPAID', 'total_amount' => 8_000_000, 'order_created_at' => '2026-07-12', 'line_items' => []]);
        TiktokOrder::create(['tiktok_order_id' => 'T-4', 'status' => 'CANCELLED', 'total_amount' => 8_000_000, 'order_created_at' => '2026-07-13', 'line_items' => []]);

        // --- Shopee ---
        ShopeeOrder::create(['order_sn' => 'SP-1', 'status' => 'COMPLETED', 'total_amount' => 400_000, 'order_created_at' => '2026-07-14', 'line_items' => []]);
        ShopeeOrder::create(['order_sn' => 'SP-2', 'status' => 'READY_TO_SHIP', 'total_amount' => 200_000, 'order_created_at' => '2026-07-15', 'line_items' => []]);

        $ch = collect(app(ReportService::class)->channelSales())->keyBy('key');

        $this->assertEqualsWithDelta(1_000_000, $ch['reseller']['confirmed'], 0.01);
        $this->assertEqualsWithDelta(500_000, $ch['reseller']['pipeline'], 0.01);
        $this->assertEqualsWithDelta(600_000, $ch['tiktok']['confirmed'], 0.01);
        $this->assertEqualsWithDelta(300_000, $ch['tiktok']['pipeline'], 0.01);
        $this->assertEqualsWithDelta(400_000, $ch['shopee']['confirmed'], 0.01);
        $this->assertEqualsWithDelta(200_000, $ch['shopee']['pipeline'], 0.01);

        // Estimasi bulan ini = 2jt terealisasi + 1jt berjalan. UNPAID (8jt),
        // CANCELLED (8jt), draft (9jt) & bulan lalu (7jt) TIDAK menggelembungkannya.
        $this->assertSame(2_000_000.0, $ch->sum('confirmed'));
        $this->assertSame(1_000_000.0, $ch->sum('pipeline'));

        // ...tapi tetap DILAPORKAN terpisah supaya cancel rate kelihatan
        $this->assertEqualsWithDelta(8_000_000, $ch['tiktok']['cancelled'], 0.01);
        $this->assertEqualsWithDelta(8_000_000, $ch['tiktok']['unpaid'], 0.01);
        $this->assertSame(4, $ch['tiktok']['orders_n']);
        $this->assertSame(25.0, $ch['tiktok']['cancel_rate']);   // 1 batal dari 4 order

        Carbon::setTestNow();
    }

    public function test_cancel_rate_counts_orders_not_rupiah(): void
    {
        Carbon::setTestNow('2026-07-16 12:00:00');
        // 1 order batal bernilai besar + 3 order selesai bernilai kecil.
        // Cancel rate harus 25% (1 dari 4), BUKAN porsi rupiahnya (yg akan ~97%).
        TiktokOrder::create(['tiktok_order_id' => 'C-BIG', 'status' => 'CANCELLED', 'total_amount' => 100_000_000, 'order_created_at' => '2026-07-10', 'line_items' => []]);
        foreach (range(1, 3) as $i) {
            TiktokOrder::create(['tiktok_order_id' => "C-{$i}", 'status' => 'COMPLETED', 'total_amount' => 1_000_000, 'order_created_at' => '2026-07-10', 'line_items' => []]);
        }

        $tt = collect(app(ReportService::class)->channelSales())->keyBy('key')['tiktok'];

        $this->assertSame(25.0, $tt['cancel_rate']);
        Carbon::setTestNow();
    }

    public function test_month_is_scoped_by_order_date(): void
    {
        $this->po('PO-JUN', 'completed', 3_000_000, '2026-06-10');
        $this->po('PO-JUL', 'completed', 1_000_000, '2026-07-10');

        $svc = app(ReportService::class);
        $jun = collect($svc->channelSales(Carbon::parse('2026-06-15')))->keyBy('key');
        $jul = collect($svc->channelSales(Carbon::parse('2026-07-15')))->keyBy('key');

        $this->assertEqualsWithDelta(3_000_000, $jun['reseller']['confirmed'], 0.01);
        $this->assertEqualsWithDelta(1_000_000, $jul['reseller']['confirmed'], 0.01);
    }

    public function test_dashboard_renders_channel_panel_for_staff(): void
    {
        $admin = User::create([
            'name' => 'A', 'fullname' => 'A', 'username' => 'chadm', 'email' => 'ch@skinku.test',
            'password' => Hash::make('secret123'),
            'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);

        $this->actingAs($admin)->get('/dashboard')->assertOk()
            ->assertSee('Penjualan per Channel')
            ->assertSee('Batal &amp; Belum Bayar', false);
    }

    public function test_dashboard_can_look_at_a_past_month(): void
    {
        $admin = User::create([
            'name' => 'B', 'fullname' => 'B', 'username' => 'chadm2', 'email' => 'ch2@skinku.test',
            'password' => Hash::make('secret123'),
            'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
        $this->po('PO-JUN', 'completed', 3_000_000, '2026-06-10');

        // bukan paten bulan berjalan — bisa menengok bulan lalu
        $this->actingAs($admin)->get('/dashboard?bulan=2026-06')->assertOk()->assertSee('3.000.000');

        // input ngawur → jatuh ke bulan berjalan, bukan error
        $this->actingAs($admin)->get('/dashboard?bulan=ngawur')->assertOk();
        $this->actingAs($admin)->get('/dashboard?bulan=2026-13')->assertOk();
    }
}
