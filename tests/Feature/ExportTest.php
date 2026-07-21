<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\KolScreening;
use App\Models\PartnerSale;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\XlsxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use ZipArchive;

class ExportTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
            'company_name' => strtoupper($u).' CO',
        ]);
    }

    /** Baca balik isi sheet dari respons download — bukti xlsx-nya zip valid. */
    private function sheetXml($response, int $sheet = 1): string
    {
        $file = $response->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file) === true, 'xlsx harus zip yang valid');
        $xml = $zip->getFromName("xl/worksheets/sheet{$sheet}.xml");
        $zip->close();
        $this->assertNotFalse($xml, 'sheet harus ada di dalam zip');

        return $xml;
    }

    /** Writer: string jadi inlineStr, angka jadi sel NUMERIK (bisa di-SUM). */
    public function test_xlsx_writer_menulis_string_dan_angka_numerik(): void
    {
        $path = XlsxWriter::write([
            'Uji' => [
                'headers' => ['Nama', 'Nilai'],
                'rows' => [['SKN-PO-TEST', 15_000_000], ['Nol koma', 33.33]],
            ],
        ]);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertNotFalse($zip->getFromName('[Content_Types].xml'));
        $this->assertNotFalse($zip->getFromName('xl/workbook.xml'));
        $zip->close();
        unlink($path);

        $this->assertStringContainsString('SKN-PO-TEST', $xml);
        $this->assertStringContainsString('<c r="B2" t="n"><v>15000000</v></c>', $xml);   // angka murni
        $this->assertStringContainsString('<v>33.33</v>', $xml);
    }

    public function test_export_laporan_penjualan_menghormati_bulan(): void
    {
        Carbon::setTestNow('2026-07-20 10:00:00');
        $admin = $this->user(User::ROLE_ADMIN, 'exadm');

        $jul = PurchaseOrder::create([
            'po_number' => 'PO-JUL-X', 'created_by' => 1, 'user_id' => 1, 'user_role' => 'reseller',
            'company_name' => 'TOKO JULI', 'status' => 'completed', 'total_amount' => 250_000,
            'order_date' => '2026-07-05', 'completed_at' => '2026-07-05',
        ]);
        PurchaseOrder::create([
            'po_number' => 'PO-JUN-X', 'created_by' => 1, 'user_id' => 1, 'user_role' => 'reseller',
            'company_name' => 'TOKO JUNI', 'status' => 'completed', 'total_amount' => 750_000,
            'order_date' => '2026-06-05', 'completed_at' => '2026-06-05',
        ]);

        $res = $this->actingAs($admin)->get('/reports/export?bulan=2026-07')->assertOk();
        $this->assertStringContainsString('spreadsheetml', $res->headers->get('content-type'));

        // Sheet 1 = Ringkasan: total Juli 250rb sebagai ANGKA, bukan 1jt gabungan.
        $xml = $this->sheetXml($res, 1);
        $this->assertStringContainsString('<v>250000</v>', $xml);
        $this->assertStringNotContainsString('<v>1000000</v>', $xml);

        // Sheet Per Mitra memuat nama mitranya.
        $xml4 = $this->sheetXml($res, 4);
        $this->assertStringContainsString('TOKO JULI', $xml4);
        $this->assertStringNotContainsString('TOKO JUNI', $xml4);

        Carbon::setTestNow();
    }

    public function test_export_stok_hq_hanya_untuk_pemegang_manage_hq_stock(): void
    {
        $mitra = $this->user(User::ROLE_DISTRIBUTOR, 'exmitra');
        $this->actingAs($mitra)->get('/laporan-stok-hq/export')->assertForbidden();

        $admin = $this->user(User::ROLE_ADMIN, 'exadm2');
        $p = Product::create([
            'name' => 'MIZU EXPORT', 'sku' => 'MZ-EX', 'hq_stock' => 346, 'status' => 'active',
            'cogs' => 10_000, 'price_distributor' => 20_000, 'price_reseller' => 25_000,
        ]);
        StockMovement::create([
            'product_id' => $p->id, 'user_id' => null, 'movement_type' => 'IN',
            'quantity' => 346, 'before_qty' => 0, 'after_qty' => 346,
            'reference_type' => 'production', 'reference_id' => 1,
        ]);

        $res = $this->actingAs($admin)->get('/laporan-stok-hq/export?mode=harian&date='.now()->format('Y-m-d'))->assertOk();
        $xml = $this->sheetXml($res);
        $this->assertStringContainsString('MIZU EXPORT', $xml);
        $this->assertStringContainsString('TOTAL', $xml);
    }

    public function test_export_listing_kol_memuat_baris_screening(): void
    {
        $spec = $this->user('kol_specialist', 'exspec');
        $kol = Kol::create(['tiktok_username' => 'exportkol', 'followers' => 6_956, 'agency' => 'OUR GOOD MEDIA']);
        KolScreening::create([
            'kol_id' => $kol->id, 'tanggal_listing' => '2026-04-22', 'ratecard' => 55_000,
            'views_1' => 105_200, 'views_2' => 6_627, 'views_3' => 1_165, 'views_4' => 131_400,
            'views_5' => 2_874, 'views_6' => 1_040, 'views_7' => 11_000,
        ]);

        $res = $this->actingAs($spec)->get('/kols/listing/export')->assertOk();
        $xml = $this->sheetXml($res);

        $this->assertStringContainsString('@exportkol', $xml);
        $this->assertStringContainsString('<v>3021912</v>', $xml);      // GMV numerik (baris Excel asli)
        $this->assertStringContainsString('OUR GOOD MEDIA', $xml);
        $this->assertStringContainsString('Worth It', $xml);

        // Database KOL export juga jalan untuk pemegang kol.view.
        $res2 = $this->actingAs($spec)->get('/kols/export')->assertOk();
        $this->assertStringContainsString('@exportkol', $this->sheetXml($res2));

        // Tanpa kol.view → 403.
        $this->actingAs($this->user(User::ROLE_GUDANG, 'exgud'))->get('/kols/listing/export')->assertForbidden();
    }

    public function test_export_po_mitra_hanya_memuat_po_miliknya(): void
    {
        $mitraA = $this->user(User::ROLE_DISTRIBUTOR, 'expoa');
        $mitraB = $this->user(User::ROLE_DISTRIBUTOR, 'expob');

        PurchaseOrder::create([
            'po_number' => 'PO-MILIK-A', 'created_by' => $mitraA->id, 'user_id' => $mitraA->id,
            'user_role' => 'distributor', 'company_name' => 'A CO', 'status' => 'completed', 'total_amount' => 100,
        ]);
        PurchaseOrder::create([
            'po_number' => 'PO-MILIK-B', 'created_by' => $mitraB->id, 'user_id' => $mitraB->id,
            'user_role' => 'distributor', 'company_name' => 'B CO', 'status' => 'completed', 'total_amount' => 200,
        ]);

        $xml = $this->sheetXml($this->actingAs($mitraA)->get('/purchase-orders/export')->assertOk());
        $this->assertStringContainsString('PO-MILIK-A', $xml);
        $this->assertStringNotContainsString('PO-MILIK-B', $xml);   // milik orang lain TIDAK bocor

        // Staf melihat semuanya.
        $admin = $this->user(User::ROLE_ADMIN, 'exadm3');
        $xml = $this->sheetXml($this->actingAs($admin)->get('/purchase-orders/export')->assertOk());
        $this->assertStringContainsString('PO-MILIK-A', $xml);
        $this->assertStringContainsString('PO-MILIK-B', $xml);
    }

    public function test_export_penjualan_customer_hanya_mitra_dan_hanya_miliknya(): void
    {
        $admin = $this->user(User::ROLE_ADMIN, 'exadm4');
        $this->actingAs($admin)->get('/inventory/sales/export')->assertForbidden();   // bukan mitra

        $mitra = $this->user(User::ROLE_DISTRIBUTOR, 'exps');
        $sale = PartnerSale::create([
            'sale_number' => 'SALE-X1', 'user_id' => $mitra->id, 'customer_name' => 'Bu Rina',
            'total_amount' => 75_000, 'sold_at' => '2026-07-10', 'created_by' => $mitra->id,
        ]);
        $sale->items()->create([
            'product_id' => Product::create([
                'name' => 'P Ex', 'sku' => 'PX-1', 'hq_stock' => 0, 'status' => 'active',
                'cogs' => 1, 'price_distributor' => 1, 'price_reseller' => 1,
            ])->id,
            'product_name' => 'P Ex', 'qty' => 3, 'unit_price' => 25_000, 'total_price' => 75_000,
        ]);

        $xml = $this->sheetXml($this->actingAs($mitra)->get('/inventory/sales/export?bulan=2026-07')->assertOk());
        $this->assertStringContainsString('SALE-X1', $xml);
        $this->assertStringContainsString('Bu Rina', $xml);
        $this->assertStringContainsString('<v>75000</v>', $xml);
    }
}
