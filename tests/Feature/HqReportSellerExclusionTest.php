<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\PurchaseOrderService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HqReportSellerExclusionTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $role, ?int $upline = null): User
    {
        $u = 'u'.(++$this->seq);

        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'company_name' => 'CV '.$u, 'role' => $role,
            'status' => User::STATUS_ACTIVE, 'upline_id' => $upline,
        ]);
    }

    private function product(int $hqStock = 1000): Product
    {
        return Product::create([
            'name' => 'P'.(++$this->seq), 'sku' => 'SKU-'.$this->seq,
            'price_distributor' => 20000, 'price_reseller' => 25000, 'price_retail' => 39000,
            'cogs' => 10000, 'hq_stock' => $hqStock, 'status' => 'active',
        ]);
    }

    private function stock(User $u, Product $p, int $qty): void
    {
        Inventory::create(['user_id' => $u->id, 'product_id' => $p->id, 'quantity' => $qty]);
    }

    private function svc(): PurchaseOrderService
    {
        return app(PurchaseOrderService::class);
    }

    private function report(): ReportService
    {
        return app(ReportService::class);
    }

    /** Bikin PO selesai. seller null (HQ) kalau buyer tanpa upline; inter-partner kalau buyer punya upline (stok upline disiapkan). */
    private function completedPo(User $buyer, Product $p, int $qty): void
    {
        if ($buyer->upline_id) {
            $seller = User::find($buyer->upline_id);
            $this->stock($seller, $p, $qty);
        }
        $po = $this->svc()->createForPartner($buyer, [['product_id' => $p->id, 'qty' => $qty]], null, null);
        if ($buyer->upline_id) {
            // Routing Model X mati — createForPartner tak lagi set seller_id.
            // Set manual di sini biar skenario inter-partner (dorman) tetap
            // teruji (lihat InterPartnerFulfillmentTest, Task 1).
            $po->seller_id = $buyer->upline_id;
            $po->save();
        }
        $this->svc()->complete($po);
    }

    public function test_omzet_hq_kecualikan_po_antar_mitra(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);              // beli dari HQ (seller null)
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);         // beli dari grand (inter-partner)
        $p = $this->product();

        // Urutan: inter-partner dulu, baru HQ→grand. completedPo() men-seed stok upline
        // via Inventory::create (raw insert, bukan upsert) — kalau HQ→grand jalan duluan,
        // grand sudah punya baris inventory (dikreditkan PurchaseOrderService::complete()),
        // dan insert kedua akan tabrakan dengan unique(user_id, product_id). summary() di
        // bawah membaca state akhir DB, jadi urutan pembuatan PO tidak memengaruhi hasil.
        $this->completedPo($dist, $p, 5);   // grand→dist: BUKAN omzet HQ
        $this->completedPo($grand, $p, 3);  // HQ→grand: omzet HQ

        $summary = $this->report()->summary(null); // viewer null = HQ
        // total_sales HQ = hanya PO seller-null (3 unit @ harga grand), bukan yang 5.
        $this->assertGreaterThan(0, $summary['total_sales']);
        // Bukti kunci: nilai = PO HQ saja. Hitung ekspektasi dari PO seller-null di DB.
        $expectedHq = PurchaseOrder::whereNull('seller_id')
            ->where('status', PurchaseOrder::STATUS_COMPLETED)->sum('total_amount');
        $this->assertEqualsWithDelta((float) $expectedHq, (float) $summary['total_sales'], 0.01);
        // dan lebih kecil dari total semua PO (ada inter-partner yang dibuang).
        $allPo = PurchaseOrder::where('status', PurchaseOrder::STATUS_COMPLETED)->sum('total_amount');
        $this->assertLessThan((float) $allPo, (float) $summary['total_sales']);
    }

    public function test_view_mitra_sendiri_tetap_hitung_pembelian_dari_upline(): void
    {
        // Regresi: distributor yang beli dari upline harus TETAP lihat pembeliannya di ringkasannya sendiri.
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $p = $this->product();
        $this->completedPo($dist, $p, 5); // inter-partner: dist sebagai pembeli

        $summary = $this->report()->summary($dist); // viewer = mitra pembeli
        $this->assertGreaterThan(0, $summary['total_sales']); // pembeliannya TAK boleh hilang
    }

    public function test_penjualan_ke_downline_di_summary_dan_dashboard(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $p = $this->product();
        $this->completedPo($dist, $p, 5); // dist beli ke grand → grand jadi seller

        // B (data): downline_sales grand = nilai PO di mana dia seller.
        $expected = (float) PurchaseOrder::where('seller_id', $grand->id)
            ->where('status', PurchaseOrder::STATUS_COMPLETED)->sum('total_amount');
        $this->assertGreaterThan(0, $expected);
        $this->assertEqualsWithDelta($expected, (float) $this->report()->summary($grand)['downline_sales'], 0.01);
        // Pembeli (dist) & HQ (viewer null) tak punya penjualan downline → 0.
        $this->assertSame(0.0, (float) $this->report()->summary($dist)['downline_sales']);
        $this->assertSame(0.0, (float) $this->report()->summary(null)['downline_sales']);

        // A + B (view): dashboard GD relabel "Belanja ke HQ" + kartu "Penjualan ke Downline".
        $html = $this->actingAs($grand)->get('/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('Belanja ke HQ', $html);
        $this->assertStringContainsString('Penjualan ke Downline', $html);

        // Distributor juga stockist → dapat kartu Downline; TAPI label belanja "Belanja"
        // (bukan "ke HQ" — distri beli ke GD-nya, bukan HQ).
        $distHtml = $this->actingAs($dist)->get('/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('Penjualan ke Downline', $distHtml);
        $this->assertStringNotContainsString('Belanja ke HQ', $distHtml);
    }

    public function test_laporan_penjualan_downline_data_dan_akses(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $p = $this->product();
        $this->completedPo($dist, $p, 5); // dist beli ke grand @ 20rb → 100rb

        // Data laporan
        $rep = $this->report()->downlineSalesReport($grand, null);
        $this->assertEqualsWithDelta(100_000, (float) $rep['net'], 0.01);
        $this->assertSame(1, $rep['completed']);
        $this->assertNotEmpty($rep['per_buyer']);
        $this->assertSame($dist->fullname, $rep['per_buyer'][0]['nama']);
        $this->assertNotEmpty($rep['per_product']);

        // Akses: GD (stockist) boleh & lihat downline-nya.
        $this->actingAs($grand)->get(route('reports.downline-sales'))->assertOk()
            ->assertSee('Laporan Penjualan ke Downline')->assertSee($dist->fullname);

        // Reseller Bronze (tak pegang stok / tak punya view_reports) → 403.
        $bronze = $this->user(User::ROLE_RESELLER_BRONZE);
        $this->actingAs($bronze)->get(route('reports.downline-sales'))->assertForbidden();
    }
}
