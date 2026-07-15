<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\HqStockReportService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HqStockReportTest extends TestCase
{
    use RefreshDatabase;

    private function product(int $stock = 0): Product
    {
        static $n = 0;
        $n++;

        return Product::create([
            'name' => "P{$n}", 'sku' => "SKU-{$n}", 'hq_stock' => $stock,
            'status' => 'active', 'price_distributor' => 1, 'price_reseller' => 1,
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'A', 'fullname' => 'A', 'username' => 'adm', 'email' => 'a@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_report_buckets_and_balances_reconcile(): void
    {
        $inv = app(InventoryService::class);
        $p = $this->product(0);

        $day = Carbon::parse('2026-07-14 08:00:00');

        // opening baseline via opname (dated end of 13 Jul), stok jadi 1000
        $inv->adjustHqStock($p, 1000, StockMovement::TYPE_ADJUSTMENT, 'opname', 'opname',
            occurredAt: Carbon::parse('2026-07-14')->startOfDay()->subSecond());
        // produksi masuk +200 pada 14 Jul
        $inv->adjustHqStock($p, 200, StockMovement::TYPE_IN, null, 'production', occurredAt: $day);
        // keluar TikTok 500, Reseller 100 pada 14 Jul
        $inv->adjustHqStock($p, -500, StockMovement::TYPE_OUT, null, 'tiktok_order', occurredAt: $day);
        $inv->adjustHqStock($p, -100, StockMovement::TYPE_OUT, null, 'purchase_order', occurredAt: $day);

        $rep = app(HqStockReportService::class)->report('harian', Carbon::parse('2026-07-14'));
        $row = collect($rep['rows'])->firstWhere('product.id', $p->id);

        $this->assertSame(1000, $row['awal']);      // opname jadi saldo awal
        $this->assertSame(200, $row['produksi']);
        $this->assertSame(500, $row['tiktok']);
        $this->assertSame(100, $row['reseller']);
        $this->assertSame(0, $row['shopee']);
        // akhir = 1000 + 200 - 500 - 100 = 600
        $this->assertSame(600, $row['akhir']);
        // identitas neraca stok
        $this->assertSame(
            $row['awal'] + $row['produksi'] + $row['masuk_lain'] + $row['penyesuaian']
                - $row['tiktok'] - $row['shopee'] - $row['reseller'] - $row['keluar_lain'],
            $row['akhir']
        );
    }

    public function test_daily_closing_carries_to_next_day_opening(): void
    {
        $inv = app(InventoryService::class);
        $p = $this->product(0);

        $inv->adjustHqStock($p, 1000, StockMovement::TYPE_ADJUSTMENT, 'opname', 'opname',
            occurredAt: Carbon::parse('2026-07-14')->startOfDay()->subSecond());
        $inv->adjustHqStock($p, -300, StockMovement::TYPE_OUT, null, 'tiktok_order',
            occurredAt: Carbon::parse('2026-07-14 10:00:00'));
        $inv->adjustHqStock($p, -50, StockMovement::TYPE_OUT, null, 'tiktok_order',
            occurredAt: Carbon::parse('2026-07-15 10:00:00'));

        $svc = app(HqStockReportService::class);
        $d14 = collect($svc->report('harian', Carbon::parse('2026-07-14'))['rows'])->firstWhere('product.id', $p->id);
        $d15 = collect($svc->report('harian', Carbon::parse('2026-07-15'))['rows'])->firstWhere('product.id', $p->id);

        $this->assertSame(700, $d14['akhir']);
        $this->assertSame(700, $d15['awal']);   // akhir 14 = awal 15
        $this->assertSame(650, $d15['akhir']);
    }

    public function test_monthly_aggregates_the_whole_month(): void
    {
        $inv = app(InventoryService::class);
        $p = $this->product(0);

        $inv->adjustHqStock($p, 1000, StockMovement::TYPE_ADJUSTMENT, 'opname', 'opname',
            occurredAt: Carbon::parse('2026-07-01')->startOfDay()->subSecond());
        $inv->adjustHqStock($p, -200, StockMovement::TYPE_OUT, null, 'tiktok_order', occurredAt: Carbon::parse('2026-07-05'));
        $inv->adjustHqStock($p, -100, StockMovement::TYPE_OUT, null, 'tiktok_order', occurredAt: Carbon::parse('2026-07-20'));

        $row = collect(app(HqStockReportService::class)->report('bulanan', Carbon::parse('2026-07-15'))['rows'])
            ->firstWhere('product.id', $p->id);

        $this->assertSame(1000, $row['awal']);
        $this->assertSame(300, $row['tiktok']);   // 200 + 100 sebulan
        $this->assertSame(700, $row['akhir']);
    }

    public function test_pages_render(): void
    {
        $this->product(50);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('stok-opname.index'))->assertOk()->assertSee('Stok Opname');
        $this->actingAs($admin)->get(route('hq-stock.report', ['mode' => 'harian', 'date' => '2026-07-14']))->assertOk()->assertSee('Mutasi Stok HQ');
        $this->actingAs($admin)->get(route('hq-stock.report', ['mode' => 'bulanan', 'date' => '2026-07']))->assertOk();
    }

    public function test_movement_drilldown_filters_by_product_and_period(): void
    {
        $inv = app(InventoryService::class);
        $a = $this->product(0);
        $b = $this->product(0);

        $inv->adjustHqStock($a, 100, StockMovement::TYPE_IN, null, 'production', occurredAt: Carbon::parse('2026-07-14 09:00'));
        $inv->adjustHqStock($a, -30, StockMovement::TYPE_OUT, null, 'tiktok_order', occurredAt: Carbon::parse('2026-07-14 10:00'));
        $inv->adjustHqStock($a, -10, StockMovement::TYPE_OUT, null, 'tiktok_order', occurredAt: Carbon::parse('2026-07-20 10:00')); // luar periode
        $inv->adjustHqStock($b, 50, StockMovement::TYPE_IN, null, 'production', occurredAt: Carbon::parse('2026-07-14 09:00')); // produk lain

        $this->actingAs($this->admin())
            ->get(route('stock-movements.index', ['product_id' => $a->id, 'from' => '2026-07-14', 'to' => '2026-07-14']))
            ->assertOk()
            ->assertSee('Detail dari')
            ->assertSee($a->name);

        // hanya 2 gerakan produk A dalam tanggal 14 yang lolos filter
        $count = StockMovement::query()
            ->where('product_id', $a->id)->whereNull('user_id')
            ->whereBetween('created_at', ['2026-07-14 00:00:00', '2026-07-14 23:59:59'])
            ->count();
        $this->assertSame(2, $count);
    }

    public function test_opname_endpoint_writes_adjustment_and_syncs_stock(): void
    {
        $p = $this->product(120);

        $this->actingAs($this->admin())
            ->post(route('stok-opname.store'), [
                'opname_date' => '2026-07-14',
                'counts' => [$p->id => 100],   // fisik 100, sistem 120 → selisih -20
            ])
            ->assertRedirect();

        $this->assertSame(100, (int) $p->fresh()->hq_stock);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $p->id, 'reference_type' => 'opname', 'movement_type' => 'ADJUSTMENT',
        ]);
    }
}
