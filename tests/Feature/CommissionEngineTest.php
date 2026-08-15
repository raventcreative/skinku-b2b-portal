<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Commission;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CommissionEngineTest extends TestCase
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

    private function product(): Product
    {
        return Product::create([
            'name' => 'P'.(++$this->seq), 'sku' => 'SKU-'.$this->seq,
            'price_distributor' => 24000, 'price_reseller' => 29000, 'price_retail' => 39000,
            'cogs' => 10000, 'hq_stock' => 1000, 'status' => Product::STATUS_ACTIVE,
        ]);
    }

    private function svc(): PurchaseOrderService
    {
        return app(PurchaseOrderService::class);
    }

    private function commissionSvc(): CommissionService
    {
        return app(CommissionService::class);
    }

    /** PO selesai dengan harga tier normal (qty × harga tier) — dipakai buat "habiskan slot order pertama". */
    private function completedPo(User $buyer, Product $p, int $qty): PurchaseOrder
    {
        $po = $this->svc()->createForPartner($buyer, [['product_id' => $p->id, 'qty' => $qty]], null, null);

        return $this->svc()->complete($po);
    }

    /** PO selesai dengan subtotal PERSIS senilai $subtotal (qty=1, harga di-override). */
    private function completedPoValue(User $buyer, Product $p, float $subtotal): PurchaseOrder
    {
        $po = $this->svc()->createForPartner(
            $buyer,
            [['product_id' => $p->id, 'qty' => 1]],
            null,
            null,
            [$p->id => $subtotal],
        );

        return $this->svc()->complete($po);
    }

    private function commissionFor(User $u, PurchaseOrder $po): float
    {
        return (float) Commission::where('user_id', $u->id)->where('source_po_id', $po->id)->sum('amount');
    }

    public function test_override_differensial_naik_pohon_saat_repeat(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $reseller = $this->user(User::ROLE_RESELLER_BRONZE, $dist->id);
        $p = $this->product();
        // Order PERTAMA reseller = join (biar order berikutnya = override)
        $this->completedPo($reseller, $p, 1);
        // Order KEDUA reseller (repeat) senilai barang 100.000
        $po = $this->completedPoValue($reseller, $p, 100000);

        // Override: Dist 4% = 4000, Grand 6% = 6000 (dari base 100.000)
        $this->assertEqualsWithDelta(4000, $this->commissionFor($dist, $po), 0.01);
        $this->assertEqualsWithDelta(6000, $this->commissionFor($grand, $po), 0.01);
        $this->assertSame(0.0, $this->commissionFor($reseller, $po)); // pembeli tak dapat
    }

    public function test_order_pertama_join_upline_langsung_10persen(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $po = $this->completedPoValue($dist, $this->product(), 200000); // order pertama dist
        // Join: upline langsung (grand) 10% = 20.000; TIDAK ada override lain di order pertama
        $this->assertEqualsWithDelta(20000, $this->commissionFor($grand, $po), 0.01);
    }

    public function test_rate_dari_appsetting(): void
    {
        AppSetting::put('komisi_persen_grand_distributor', '10');

        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $reseller = $this->user(User::ROLE_RESELLER_BRONZE, $dist->id);
        $p = $this->product();
        $this->completedPo($reseller, $p, 1); // habiskan slot order pertama (join)
        $po = $this->completedPoValue($reseller, $p, 100000); // repeat → override, base 100.000

        // Rate custom grand 10% → 10.000 (bukan default 6%)
        $this->assertEqualsWithDelta(10000, $this->commissionFor($grand, $po), 0.01);
    }

    public function test_pembeli_tanpa_upline_nol_komisi(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR); // upline null
        $po = $this->completedPoValue($grand, $this->product(), 100000);
        $this->assertSame(0, Commission::where('source_po_id', $po->id)->count());
    }

    public function test_idempoten_tidak_dobel(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $p = $this->product();
        $this->completedPo($dist, $p, 1); // habiskan slot order pertama
        $po = $this->completedPoValue($dist, $p, 100000); // repeat → override → grand dapat baris

        $before = Commission::where('source_po_id', $po->id)->count();
        $this->assertGreaterThan(0, $before); // sanity: complete() sudah menulis komisi via hook

        $this->commissionSvc()->recordForCompletedPo($po); // panggil manual ke-2 kali
        $after = Commission::where('source_po_id', $po->id)->count();

        $this->assertSame($before, $after); // tidak dobel
    }

    public function test_saldo_jumlah_komisi(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $p = $this->product();
        $this->completedPo($dist, $p, 1); // order pertama dist → join ke grand
        $this->completedPoValue($dist, $p, 100000); // repeat → override ke grand (level 1)

        $expected = (float) Commission::where('user_id', $grand->id)->where('status', 'saldo')->sum('amount');
        $this->assertGreaterThan(0, $expected);
        $this->assertEqualsWithDelta($expected, $this->commissionSvc()->balance($grand), 0.01);
    }
}
