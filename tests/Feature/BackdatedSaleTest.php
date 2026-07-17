<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\PurchaseOrderService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BackdatedSaleTest extends TestCase
{
    use RefreshDatabase;

    private function partner(): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => "Erin{$n}", 'fullname' => "Erin{$n}", 'username' => "erin{$n}",
            'email' => "erin{$n}@skinku.test", 'password' => Hash::make('secret123'),
            'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE,
            'company_name' => "Toko Erin {$n}",
        ]);
    }

    private function admin(): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => 'A', 'fullname' => 'A', 'username' => "bdadm{$n}", 'email' => "bd{$n}@skinku.test",
            'password' => Hash::make('secret123'),
            'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function product(int $stock = 100): Product
    {
        static $n = 0;
        $n++;

        return Product::create([
            'name' => "P{$n}", 'sku' => "BD-{$n}", 'hq_stock' => $stock, 'status' => 'active',
            'cogs' => 10_000, 'price_distributor' => 20_000, 'price_reseller' => 25_000,
        ]);
    }

    public function test_backdated_sale_records_revenue_but_never_touches_stock(): void
    {
        // Opname 14 Jul sore → potong stok mulai 15 Jul (sama seperti TikTok).
        AppSetting::put(AppSetting::PO_DEDUCT_FROM, '2026-07-15');
        $svc = app(PurchaseOrderService::class);
        $p = $this->product(100);
        $erin = $this->partner();

        $po = $svc->recordBackdatedSale(
            $erin, [['product_id' => $p->id, 'qty' => 12]],
            Carbon::parse('2026-07-08'), 'Backfill dari Excel', $this->admin()->id,
        );

        // penjualan tercatat & selesai...
        $this->assertSame(PurchaseOrder::STATUS_COMPLETED, $po->status);
        $this->assertEqualsWithDelta(12 * 25_000, (float) $po->total_amount, 0.01);
        // ...tapi stok TIDAK berkurang — barangnya sudah keluar sebelum opname
        $this->assertSame(100, (int) $p->fresh()->hq_stock);
        $this->assertTrue($po->fresh()->stock_skipped);
        // tak ada gerakan stok sama sekali (HQ maupun mitra)
        $this->assertDatabaseMissing('stock_movements', ['reference_type' => 'purchase_order', 'reference_id' => $po->id]);
        $this->assertDatabaseMissing('inventory', ['user_id' => $erin->id, 'product_id' => $p->id]);
    }

    public function test_sale_on_or_after_cutoff_deducts_stock_normally(): void
    {
        AppSetting::put(AppSetting::PO_DEDUCT_FROM, '2026-07-15');
        $svc = app(PurchaseOrderService::class);
        $p = $this->product(100);
        $erin = $this->partner();

        $po = $svc->recordBackdatedSale(
            $erin, [['product_id' => $p->id, 'qty' => 4]],
            Carbon::parse('2026-07-15'), null, $this->admin()->id,   // tepat di batas
        );

        $this->assertSame(96, (int) $p->fresh()->hq_stock);   // dipotong
        $this->assertFalse($po->fresh()->stock_skipped);
        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => 'purchase_order', 'reference_id' => $po->id, 'movement_type' => 'OUT',
        ]);
        // mitra menerima barangnya
        $this->assertDatabaseHas('inventory', ['user_id' => $erin->id, 'product_id' => $p->id, 'quantity' => 4]);
    }

    public function test_without_cutoff_every_po_still_deducts(): void
    {
        // Tanpa setelan batas, perilaku lama harus utuh — jangan diam-diam
        // berhenti memotong stok.
        $svc = app(PurchaseOrderService::class);
        $p = $this->product(100);

        $po = $svc->recordBackdatedSale(
            $this->partner(), [['product_id' => $p->id, 'qty' => 7]],
            Carbon::parse('2020-01-01'), null, $this->admin()->id,
        );

        $this->assertSame(93, (int) $p->fresh()->hq_stock);
        $this->assertFalse($po->fresh()->stock_skipped);
    }

    public function test_form_records_sale_end_to_end_without_touching_stock(): void
    {
        AppSetting::put(AppSetting::PO_DEDUCT_FROM, '2026-07-15');
        $p = $this->product(100);
        $erin = $this->partner();

        $this->actingAs($this->admin())->post(route('backdated-sales.store'), [
            'user_id' => $erin->id,
            'order_date' => '2026-07-08',
            'notes' => 'Backfill Excel',
            'items' => [['product_id' => $p->id, 'qty' => 12]],
        ])->assertRedirect(route('backdated-sales.index'))->assertSessionHas('status');

        $this->assertSame(100, (int) $p->fresh()->hq_stock);   // stok utuh
        $this->assertDatabaseHas('purchase_orders', [
            'user_id' => $erin->id, 'status' => 'completed', 'stock_skipped' => true,
        ]);
        $po = PurchaseOrder::where('user_id', $erin->id)->first();
        $this->assertSame('2026-07-08', $po->orderDate()->toDateString());
    }

    public function test_manual_price_overrides_current_tier_price(): void
    {
        AppSetting::put(AppSetting::PO_DEDUCT_FROM, '2026-07-15');
        $p = $this->product();          // price_reseller = 25.000 (harga SEKARANG)
        $erin = $this->partner();

        // Entri Excel lama: 100 sabun @23.000 — harga lama, bukan tier sekarang.
        $this->actingAs($this->admin())->post(route('backdated-sales.store'), [
            'user_id' => $erin->id,
            'order_date' => '2026-01-19',
            'items' => [['product_id' => $p->id, 'qty' => 100, 'price' => 23_000]],
        ])->assertRedirect();

        $po = PurchaseOrder::where('user_id', $erin->id)->with('items')->first();
        $this->assertEqualsWithDelta(23_000, (float) $po->items[0]->unit_price, 0.01);
        $this->assertEqualsWithDelta(2_300_000, (float) $po->total_amount, 0.01);  // bukan 2.5jt dari tier
    }

    public function test_blank_price_falls_back_to_tier_price(): void
    {
        AppSetting::put(AppSetting::PO_DEDUCT_FROM, '2026-07-15');
        $p = $this->product();
        $erin = $this->partner();

        $this->actingAs($this->admin())->post(route('backdated-sales.store'), [
            'user_id' => $erin->id,
            'order_date' => '2026-01-19',
            'items' => [['product_id' => $p->id, 'qty' => 2, 'price' => null]],
        ])->assertRedirect();

        $po = PurchaseOrder::where('user_id', $erin->id)->first();
        $this->assertEqualsWithDelta(50_000, (float) $po->total_amount, 0.01);   // 2 × 25rb tier
    }

    public function test_partner_form_pricing_still_server_side_only(): void
    {
        // Mitra memesan sendiri: harga TETAP dari server. Jangan sampai dukungan
        // harga manual membocorkan kendali harga ke form mitra.
        $p = $this->product();
        $erin = $this->partner();

        $po = app(PurchaseOrderService::class)->createForPartner(
            $erin, [['product_id' => $p->id, 'qty' => 2, 'price' => 1]], null, null,
        );

        $this->assertEqualsWithDelta(50_000, (float) $po->total_amount, 0.01);  // tier, bukan 1
    }

    public function test_one_time_buyer_needs_no_account(): void
    {
        // "Vani" beli sekali — tak perlu dibuatkan akun; mitra di form hanya
        // dipakai untuk tier harga, namanya yang tersimpan di PO.
        AppSetting::put(AppSetting::PO_DEDUCT_FROM, '2026-07-15');
        $p = $this->product();
        $erin = $this->partner();
        $admin = $this->admin();
        $usersBefore = User::count();   // hitung SETELAH semua akun uji dibuat

        $this->actingAs($admin)->post(route('backdated-sales.store'), [
            'user_id' => $erin->id,
            'buyer_name' => 'Vani',
            'order_date' => '2026-03-18',
            'items' => [['product_id' => $p->id, 'qty' => 10, 'price' => 26_400]],
        ])->assertRedirect();

        $po = PurchaseOrder::latest('id')->first();
        $this->assertSame('Vani', $po->company_name);
        $this->assertEqualsWithDelta(264_000, (float) $po->total_amount, 0.01);
        // tidak ada akun baru dibuat (admin yang login sudah dihitung sebelumnya)
        $this->assertSame($usersBefore, User::count());
    }

    public function test_buyer_name_blank_keeps_partner_name(): void
    {
        AppSetting::put(AppSetting::PO_DEDUCT_FROM, '2026-07-15');
        $p = $this->product();
        $erin = $this->partner();

        $this->actingAs($this->admin())->post(route('backdated-sales.store'), [
            'user_id' => $erin->id,
            'order_date' => '2026-03-18',
            'items' => [['product_id' => $p->id, 'qty' => 1]],
        ])->assertRedirect();

        $this->assertSame($erin->company_name, PurchaseOrder::latest('id')->first()->company_name);
    }

    public function test_history_is_not_capped_and_totals_per_month(): void
    {
        AppSetting::put(AppSetting::PO_DEDUCT_FROM, '2026-07-15');
        $p = $this->product();
        $erin = $this->partner();
        $admin = $this->admin();
        $svc = app(PurchaseOrderService::class);

        // 30 entri Juni (> 10, batas pratinjau lama) + 1 entri Mei
        foreach (range(1, 30) as $i) {
            $svc->recordBackdatedSale($erin, [['product_id' => $p->id, 'qty' => 1, 'price' => 10_000]],
                Carbon::parse('2026-06-10'), null, $admin->id);
        }
        $svc->recordBackdatedSale($erin, [['product_id' => $p->id, 'qty' => 1, 'price' => 999_000]],
            Carbon::parse('2026-05-10'), null, $admin->id);

        // Tanpa saringan: semua entri terhitung (31), bukan cuma 10
        $this->actingAs($admin)->get(route('backdated-sales.index'))->assertOk()
            ->assertSee('31')->assertSee('1.299.000');   // 30×10rb + 999rb

        // Disaring per bulan → total Juni saja, untuk dicocokkan dengan Excel
        $this->actingAs($admin)->get(route('backdated-sales.index', ['entri_bulan' => '2026-06']))->assertOk()
            ->assertSee('300.000')->assertDontSee('1.299.000');

        // Bulan kosong tidak error
        $this->actingAs($admin)->get(route('backdated-sales.index', ['entri_bulan' => '2020-01']))->assertOk()
            ->assertSee('Tidak ada entri back-date pada bulan ini.');
    }

    public function test_page_renders_and_partner_forbidden(): void
    {
        $this->actingAs($this->admin())->get(route('backdated-sales.index'))->assertOk()
            ->assertSee('Catat Penjualan Distributor');
        // mitra tak boleh mencatat penjualan atas nama siapa pun
        $this->actingAs($this->partner())->get(route('backdated-sales.index'))->assertForbidden();
    }

    public function test_cutoff_can_be_saved_from_the_page(): void
    {
        $this->actingAs($this->admin())->post(route('backdated-sales.cutoff'), ['po_deduct_from' => '2026-07-15'])
            ->assertRedirect();

        $this->assertSame('2026-07-15', AppSetting::get(AppSetting::PO_DEDUCT_FROM));
    }

    public function test_backdated_sale_lands_in_the_right_month_on_dashboard(): void
    {
        AppSetting::put(AppSetting::PO_DEDUCT_FROM, '2026-07-15');
        $svc = app(PurchaseOrderService::class);
        $p = $this->product(100);

        // dibuat HARI INI tapi tanggal ordernya Juni → harus masuk laporan Juni,
        // bukan bulan berjalan (inilah gunanya order_date terpisah dari created_at)
        $svc->recordBackdatedSale(
            $this->partner(), [['product_id' => $p->id, 'qty' => 2]],
            Carbon::parse('2026-06-20'), null, $this->admin()->id,
        );

        $reports = app(ReportService::class);
        $jun = collect($reports->channelSales(Carbon::parse('2026-06-15')))->keyBy('key');
        $jul = collect($reports->channelSales(Carbon::parse('2026-07-15')))->keyBy('key');

        $this->assertEqualsWithDelta(2 * 25_000, $jun['reseller']['confirmed'], 0.01);
        $this->assertEqualsWithDelta(0, $jul['reseller']['confirmed'], 0.01);
    }
}
