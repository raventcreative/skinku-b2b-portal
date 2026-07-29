<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\TiktokSkuMap;
use App\Models\User;
use App\Services\TikTokIncomeReportService;
use App\Support\XlsxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Fase 1: mesin Laporan Income TikTok — join Order ID + resolve bundle + kolom
 * item-besar (kategori). Diuji dengan array baris (tanpa file).
 */
class TikTokIncomeReportTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): TikTokIncomeReportService
    {
        return app(TikTokIncomeReportService::class);
    }

    public function test_join_resolve_bundle_dan_kolom_item_besar(): void
    {
        $sabun = Product::create(['name' => 'Sabun A', 'sku' => 'Soap-1', 'category' => 'Sabun', 'status' => 'active']);
        Product::create(['name' => 'Body Lotion', 'sku' => 'YK-1', 'category' => 'Lotion', 'status' => 'active']);
        // Bundle: 1 SKU 'BB-3' = 3 sabun (peta manual).
        TiktokSkuMap::create(['tiktok_sku' => 'BB-3', 'product_id' => $sabun->id, 'qty' => 3]);

        $orderRows = [
            $this->orderHeader(),
            $this->orderRow('001', 'Soap-1', 2),   // 2 sabun
            $this->orderRow('002', 'BB-3', 1),     // 3 sabun (bundle)
            $this->orderRow('003', 'YK-1', 1),     // 1 lotion
            $this->orderRow('004', 'GHOST-9', 1),  // SKU tak dikenal
        ];
        $incomeRows = [
            $this->incomeHeader(),
            $this->incomeRow('001', 70000, 90000, -20000),
            $this->incomeRow('002', 200000, 238000, -38000),
            $this->incomeRow('003', 58500, 65000, -6500),
            $this->incomeRow('999', 5000, 6000, -1000),   // tak ada di file pesanan
        ];

        $rep = $this->svc()->build($orderRows, $incomeRows);

        $this->assertSame(4, $rep['summary']['csv_read']);
        $this->assertSame(4, $rep['summary']['unique_orders']);
        $this->assertSame(4, $rep['summary']['income_orders']);
        $this->assertSame(3, $rep['summary']['matched']);
        $this->assertSame(1, $rep['summary']['unmatched']);
        $this->assertSame(['GHOST-9'], $rep['unmapped']);

        $this->assertSame(['Lotion', 'Sabun'], $rep['columns']);   // dinamis + sorted

        $byId = collect($rep['rows'])->keyBy('order_id');
        $this->assertSame(2, $byId['001']['cat_qty']['Sabun']);
        $this->assertSame(3, $byId['002']['cat_qty']['Sabun']);    // bundle 1×3
        $this->assertSame(1, $byId['003']['cat_qty']['Lotion']);
        $this->assertSame(70000.0, $byId['001']['settlement']);
        $this->assertFalse($byId['999']['matched']);
        $this->assertSame([], $byId['999']['cat_qty']);
    }

    public function test_produk_tanpa_kategori_masuk_lainnya(): void
    {
        Product::create(['name' => 'Misc', 'sku' => 'MSC-1', 'category' => null, 'status' => 'active']);
        $rep = $this->svc()->build(
            [$this->orderHeader(), $this->orderRow('001', 'MSC-1', 1)],
            [$this->incomeHeader(), $this->incomeRow('001', 1000, 1200, -200)],
        );

        $this->assertSame(['Lainnya'], $rep['columns']);
        $this->assertSame(1, collect($rep['rows'])->firstWhere('order_id', '001')['cat_qty']['Lainnya']);
    }

    public function test_seller_sku_kosong_diisi_dari_sku_id_yang_sama(): void
    {
        Product::create(['name' => 'Sabun A', 'sku' => 'Soap-1', 'category' => 'Sabun', 'status' => 'active']);
        $orderRows = [
            $this->orderHeader(),
            $this->orderRowFull('001', 'SID-1', 'Soap-1', 2),   // SID-1 = Soap-1
            $this->orderRowFull('002', 'SID-1', '', 3),          // kosong → auto Soap-1
        ];
        $rep = $this->svc()->build($orderRows, [
            $this->incomeHeader(), $this->incomeRow('001', 70000, 90000, -20000), $this->incomeRow('002', 100000, 130000, -30000),
        ]);

        $this->assertSame(1, $rep['summary']['backfilled']);
        $this->assertSame([], $rep['unmapped']);
        $byId = collect($rep['rows'])->keyBy('order_id');
        $this->assertSame(2, $byId['001']['cat_qty']['Sabun']);
        $this->assertSame(3, $byId['002']['cat_qty']['Sabun']);   // baris kosong ter-resolve
    }

    public function test_sku_id_ambigu_tidak_ditebak(): void
    {
        Product::create(['name' => 'BB Cream', 'sku' => 'BBC-1', 'category' => 'BB', 'status' => 'active']);
        Product::create(['name' => 'Day Cream', 'sku' => 'DC-1', 'category' => 'Cream', 'status' => 'active']);
        $orderRows = [
            $this->orderHeader(),
            $this->orderRowFull('001', 'AMB', 'BBC-1', 1),
            $this->orderRowFull('002', 'AMB', 'DC-1', 1),
            $this->orderRowFull('003', 'AMB', '', 1),   // kosong + SKU ID ambigu → JANGAN ditebak
        ];
        $rep = $this->svc()->build($orderRows, [$this->incomeHeader(), $this->incomeRow('003', 5000, 6000, -1000)]);

        $this->assertSame(0, $rep['summary']['backfilled']);
        $this->assertContains('AMB', $rep['unmapped']);   // dibiarkan sebagai SKU ID mentah
        $this->assertSame([], collect($rep['rows'])->firstWhere('order_id', '003')['cat_qty']);
    }

    public function test_tanpa_izin_manage_tiktok_ditolak(): void
    {
        $u = User::create([
            'name' => 'dst', 'fullname' => 'DST', 'username' => 'dst', 'email' => 'dst@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_DISTRIBUTOR, 'status' => User::STATUS_ACTIVE,
        ]);
        $this->actingAs($u)->get(route('tiktok.income'))->assertForbidden();
    }

    public function test_upload_proses_dan_unduh(): void
    {
        Product::create(['name' => 'Sabun A', 'sku' => 'Soap-1', 'category' => 'Sabun', 'status' => 'active']);
        $sa = User::create([
            'name' => 'sa', 'fullname' => 'SA', 'username' => 'sa', 'email' => 'sa@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);

        // Fixture CSV (10 kolom: [0]OrderID \t nyangkut, [6]SellerSKU, [9]Qty).
        $csv = "Order ID,a,b,c,d,SKU ID,Seller SKU,g,h,Quantity\n001\t,,,,,z,Soap-1,,,2\n";
        $csvPath = tempnam(sys_get_temp_dir(), 'ord');
        file_put_contents($csvPath, $csv);

        // Fixture XLSX income (sheet "Detail pesanan", 15 kolom).
        $incRow = array_fill(0, 15, '');
        $incRow[0] = '001';
        $incRow[1] = 'Order';
        $incRow[3] = '2026/07/29';
        $incRow[5] = 70000;
        $incRow[6] = 90000;
        $incRow[14] = -20000;
        $xlsxPath = XlsxWriter::write(['Detail pesanan' => ['headers' => array_fill(0, 15, 'h'), 'rows' => [$incRow]]]);

        $orders = new UploadedFile($csvPath, 'Semua pesanan.csv', 'text/csv', null, true);
        $income = new UploadedFile($xlsxPath, 'income.xlsx', 'application/octet-stream', null, true);

        $this->actingAs($sa)->post(route('tiktok.income.process'), ['orders' => $orders, 'income' => $income])
            ->assertRedirect(route('tiktok.income'));

        $this->actingAs($sa)->get(route('tiktok.income'))->assertOk()
            ->assertSee('Sabun')->assertSee('001');   // kolom item-besar + order id

        $this->actingAs($sa)->get(route('tiktok.income.download'))
            ->assertOk()->assertDownload('Laporan Income TikTok.xlsx');
    }

    private function orderHeader(): array
    {
        return array_fill(0, 10, 'h');
    }

    private function orderRow(string $id, string $sku, int $qty): array
    {
        $r = array_fill(0, 10, '');
        $r[0] = $id."\t";   // simulasi \t nyangkut ala file TikTok (di-trim service)
        $r[6] = $sku;
        $r[9] = (string) $qty;

        return $r;
    }

    private function orderRowFull(string $id, string $skuId, string $sellerSku, int $qty): array
    {
        $r = array_fill(0, 10, '');
        $r[0] = $id."\t";
        $r[5] = $skuId;       // SKU ID (nomor internal TikTok)
        $r[6] = $sellerSku;   // Seller SKU (bisa kosong)
        $r[9] = (string) $qty;

        return $r;
    }

    private function incomeHeader(): array
    {
        return array_fill(0, 15, 'h');
    }

    private function incomeRow(string $id, float $settle, float $rev, float $fee): array
    {
        $r = array_fill(0, 15, '');
        $r[0] = $id;
        $r[1] = 'Order';
        $r[5] = (string) $settle;
        $r[6] = (string) $rev;
        $r[14] = (string) $fee;

        return $r;
    }
}
