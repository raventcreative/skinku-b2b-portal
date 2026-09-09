<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\HqStockReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HqStockValuationTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $name, string $sku, int $stock, float $cogs, float $retail): void
    {
        Product::create([
            'name' => $name, 'sku' => $sku, 'status' => Product::STATUS_ACTIVE,
            'price_grand' => 0, 'price_distributor' => 0, 'price_reseller' => 0,
            'price_retail' => $retail, 'cogs' => $cogs, 'hq_stock' => $stock,
        ]);
    }

    public function test_nilai_persediaan_per_baris_dan_grand_total(): void
    {
        // Tanpa gerakan stok → Stok Akhir = hq_stock.
        $this->product('Produk A', 'A1', 10, 6000, 10000);  // HPP 60.000, Jual 100.000
        $this->product('Produk B', 'B1', 4, 15000, 20000);  // HPP 60.000, Jual 80.000

        $report = app(HqStockReportService::class)->report('harian', Carbon::today(), includeEmpty: true);

        $byName = collect($report['rows'])->keyBy(fn ($r) => $r['product']->name);

        $this->assertSame(10, (int) $byName['Produk A']['akhir']);
        $this->assertSame(60000.0, $byName['Produk A']['nilai_hpp']);
        $this->assertSame(100000.0, $byName['Produk A']['nilai_jual']);

        $this->assertSame(60000.0, $byName['Produk B']['nilai_hpp']);
        $this->assertSame(80000.0, $byName['Produk B']['nilai_jual']);

        $this->assertSame(120000.0, $report['totals']['nilai_hpp']);
        $this->assertSame(180000.0, $report['totals']['nilai_jual']);
    }

    public function test_halaman_laporan_tampil_kolom_dan_grand_total(): void
    {
        $admin = User::create([
            'name' => 'sa', 'fullname' => 'Super', 'username' => 'sa', 'email' => 'sa@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
        $this->product('Produk A', 'A1', 10, 6000, 10000);
        $this->product('Produk B', 'B1', 4, 15000, 20000);

        $this->actingAs($admin)->get(route('hq-stock.report'))
            ->assertOk()
            ->assertSee('Nilai Persediaan (Stok Akhir)')
            ->assertSee('120.000')   // total Nilai HPP
            ->assertSee('180.000');  // total Nilai Jual
    }
}
