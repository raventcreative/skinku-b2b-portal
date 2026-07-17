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

    public function test_each_channel_has_a_light_shade_for_the_all_pie(): void
    {
        // Pie "semua" memakai warna tua = cair, muda = berjalan. Tanpa warna muda
        // yang berbeda, dua tahap jadi tak terbedakan dalam satu lingkaran.
        foreach (app(ReportService::class)->channelSales() as $ch) {
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $ch['color']);
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $ch['color_light']);
            $this->assertNotSame($ch['color'], $ch['color_light']);
        }
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

    public function test_penjualan_card_shows_per_channel_breakdown_not_just_a_lump_sum(): void
    {
        Carbon::setTestNow('2026-07-16 12:00:00');
        $admin = User::create([
            'name' => 'D', 'fullname' => 'D', 'username' => 'chadm4', 'email' => 'ch4@skinku.test',
            'password' => Hash::make('secret123'),
            'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
        TiktokOrder::create(['tiktok_order_id' => 'BD-1', 'status' => 'COMPLETED', 'total_amount' => 70_929_655, 'order_created_at' => '2026-07-10', 'line_items' => []]);

        $res = $this->actingAs($admin)->get('/dashboard')->assertOk();

        // total DAN asal-usulnya sama-sama terlihat di kartu
        $res->assertSee('70.929.655');
        $res->assertSee('TikTok');
        $res->assertSee('Shopee');       // channel nol tetap terdaftar (biar jelas nol, bukan hilang)
        $res->assertSee('Reseller / PO');

        Carbon::setTestNow();
    }

    public function test_partner_does_not_see_cross_channel_breakdown(): void
    {
        $partner = User::create([
            'name' => 'P', 'fullname' => 'P', 'username' => 'chpart', 'email' => 'chp@skinku.test',
            'password' => Hash::make('secret123'),
            'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE,
        ]);

        $this->actingAs($partner)->get('/dashboard')->assertOk()
            ->assertDontSee('Penjualan per Channel');
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

    public function test_total_penjualan_card_covers_all_channels_not_just_po(): void
    {
        Carbon::setTestNow('2026-07-16 12:00:00');
        $admin = User::create([
            'name' => 'C', 'fullname' => 'C', 'username' => 'chadm3', 'email' => 'ch3@skinku.test',
            'password' => Hash::make('secret123'),
            'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
        // Tak ada PO sama sekali, tapi TikTok jalan. Kartu lama menulis "Rp 0" —
        // menyesatkan. Sekarang harus mencakup semua channel.
        TiktokOrder::create(['tiktok_order_id' => 'TT-K', 'status' => 'COMPLETED', 'total_amount' => 52_000_000, 'order_created_at' => '2026-07-10', 'line_items' => []]);

        // allChannels: true = mode Dashboard (lintas channel)
        $s = app(ReportService::class)->summary($admin, Carbon::parse('2026-07-15'), allChannels: true);

        $this->assertEqualsWithDelta(52_000_000, $s['total_sales'], 0.01);
        Carbon::setTestNow();
    }

    public function test_dashboard_counts_all_channels_but_reports_page_counts_po_only(): void
    {
        // Label "Penjualan" pernah berarti dua hal berbeda di dua halaman tanpa
        // penanda — sumber salah baca. Kini eksplisit lewat $allChannels.
        Carbon::setTestNow('2026-07-16 12:00:00');
        $admin = User::create([
            'name' => 'E', 'fullname' => 'E', 'username' => 'chadm5', 'email' => 'ch5@skinku.test',
            'password' => Hash::make('secret123'),
            'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
        $this->po('PO-X', 'completed', 2_000_000, '2026-07-05');
        TiktokOrder::create(['tiktok_order_id' => 'TT-X', 'status' => 'COMPLETED', 'total_amount' => 50_000_000, 'order_created_at' => '2026-07-10', 'line_items' => []]);

        $svc = app(ReportService::class);
        $bulan = Carbon::parse('2026-07-15');

        // Dashboard: lintas channel
        $this->assertEqualsWithDelta(52_000_000, $svc->summary($admin, $bulan, allChannels: true)['total_sales'], 0.01);
        // Laporan Penjualan: PO saja (omzet barang/HPP/laba di situ semua basis PO)
        $this->assertEqualsWithDelta(2_000_000, $svc->summary($admin, $bulan)['total_sales'], 0.01);

        Carbon::setTestNow();
    }

    public function test_reports_page_labels_the_period_and_scope(): void
    {
        $admin = User::create([
            'name' => 'F', 'fullname' => 'F', 'username' => 'chadm6', 'email' => 'ch6@skinku.test',
            'password' => Hash::make('secret123'),
            'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);

        $this->actingAs($admin)->get(route('reports.index'))->assertOk()
            ->assertSee('Penjualan PO')            // bukan "Total Penjualan" yg ambigu
            ->assertSee('semua periode')           // periode kartu jelas
            ->assertSee('hanya mengubah grafik, bukan angka kartu');
    }

    public function test_month_filter_scopes_po_cards_and_status_chart(): void
    {
        $this->po('PO-JUN', 'completed', 3_000_000, '2026-06-10');
        $this->po('PO-JUL', 'pending', 1_000_000, '2026-07-10');
        $svc = app(ReportService::class);

        $jun = $svc->summary(null, Carbon::parse('2026-06-15'));
        $jul = $svc->summary(null, Carbon::parse('2026-07-15'));

        $this->assertSame(1, $jun['total_po']);
        $this->assertSame(0, $jun['pending_po']);
        $this->assertSame(1, $jul['total_po']);
        $this->assertSame(1, $jul['pending_po']);

        // pie status PO ikut tersaring
        $junStatus = collect($svc->poStatusDistribution(null, Carbon::parse('2026-06-15')))->keyBy('label');
        $this->assertSame(1, $junStatus['completed']['total']);
        $this->assertSame(0, $junStatus['pending']['total']);
    }

    public function test_reports_page_keeps_all_time_numbers(): void
    {
        // Halaman Laporan Penjualan memanggil tanpa bulan → perilaku lama (semua
        // waktu) harus utuh; jangan diam-diam berubah jadi bulan berjalan.
        $this->po('PO-OLD', 'completed', 5_000_000, '2020-01-05');
        $this->po('PO-NEW', 'completed', 1_000_000, '2026-07-10');

        $s = app(ReportService::class)->summary();

        $this->assertSame(2, $s['total_po']);
        $this->assertEqualsWithDelta(6_000_000, $s['total_sales'], 0.01);
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

    public function test_trend_shows_the_lates_t_periods_not_the_oldest(): void
    {
        // 40 hari berdata. Versi lama: orderBy naik + limit → yang tergambar
        // justru hari paling TUA, padahal labelnya "hari terakhir".
        foreach (range(0, 39) as $i) {
            $d = Carbon::parse('2026-06-01')->addDays($i);
            $po = PurchaseOrder::create([
                'po_number' => 'T-'.$i, 'created_by' => 1, 'user_id' => 1,
                'status' => 'completed', 'total_amount' => 1000 + $i, 'user_role' => 'reseller',
            ]);
            // tren memakai order_date (bukan created_at); completed_at wajib terisi
            PurchaseOrder::where('id', $po->id)->update([
                'order_date' => $d->toDateString(), 'completed_at' => now(),
            ]);
        }

        $trend = app(ReportService::class)->salesTrend('day', 14);

        $this->assertCount(14, $trend);
        // 14 terakhir dari 1 Jun +39 hari = 27 Jun s/d 10 Jul
        $this->assertSame('2026-06-27', $trend[0]['label']);
        $this->assertSame('2026-07-10', $trend[13]['label']);
        // digambar lama → baru
        $this->assertLessThan($trend[13]['label'], $trend[0]['label']);
    }
}
