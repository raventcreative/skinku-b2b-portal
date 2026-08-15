<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\PartnerSale;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\PurchaseOrderService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OmzetMitraServiceTest extends TestCase
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

    private function partnerSale(User $seller, float $amount, string $soldAt = '2026-08-10'): void
    {
        PartnerSale::create([
            'sale_number' => 'PS-'.(++$this->seq), 'user_id' => $seller->id,
            'customer_name' => 'Cust '.$this->seq, 'total_amount' => $amount,
            'sold_at' => $soldAt, 'created_by' => $seller->id,
        ]);
    }

    public function test_omzet_per_mitra_gabung_jual_downline_dan_customer(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);   // beli dari grand → grand jadi seller
        $p = $this->product();
        $this->completedPo($dist, $p, 5);        // grand jual ke downline (dist)
        $downlineRp = (float) PurchaseOrder::where('seller_id', $grand->id)
            ->where('status', PurchaseOrder::STATUS_COMPLETED)->sum('total_amount');
        $this->partnerSale($grand, 40000);       // grand jual ke customer akhir

        $rows = app(ReportService::class)->omzetPerMitra(null);
        $grandRow = collect($rows)->firstWhere('user_id', $grand->id);

        $this->assertNotNull($grandRow);
        $this->assertEqualsWithDelta($downlineRp, $grandRow['jual_downline'], 0.01);
        $this->assertEqualsWithDelta(40000, $grandRow['jual_customer'], 0.01);
        $this->assertEqualsWithDelta($downlineRp + 40000, $grandRow['total'], 0.01);
        $this->assertSame('Grand Distributor', $grandRow['tier']);
    }

    public function test_omzet_per_mitra_abaikan_po_hq_dan_mitra_tanpa_jualan(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product();
        $this->completedPo($grand, $p, 3);       // HQ→grand: grand sbg PEMBELI, bukan seller → bukan jualan grand
        $rows = app(ReportService::class)->omzetPerMitra(null);
        $this->assertNull(collect($rows)->firstWhere('user_id', $grand->id)); // grand tak punya jualan → tak muncul
    }

    public function test_omzet_per_mitra_filter_bulan_termasuk_tanggal_1(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $this->partnerSale($grand, 50000, '2026-08-01'); // tepat tanggal 1 — HARUS ikut
        $this->partnerSale($grand, 30000, '2026-07-20'); // bulan lain — HARUS keluar

        $rows = app(ReportService::class)->omzetPerMitra(Carbon::parse('2026-08-15'));
        $grandRow = collect($rows)->firstWhere('user_id', $grand->id);

        $this->assertNotNull($grandRow, 'Mitra dgn penjualan Agustus harus muncul (tgl-1 tak boleh kedrop).');
        $this->assertEqualsWithDelta(50000, $grandRow['jual_customer'], 0.01); // hanya Agustus
    }
}
