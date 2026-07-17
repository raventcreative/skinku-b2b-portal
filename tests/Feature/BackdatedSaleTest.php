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
