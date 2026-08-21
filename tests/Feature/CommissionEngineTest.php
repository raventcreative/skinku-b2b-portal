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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Model A: override komisi DIPADAMKAN (rate default 0, dorman & revivable). Untung
 * mitra dari MARGIN (beli-dari-upline). Distributor dgn upline-GD → PO inter-partner
 * (seller_id=GD) → tak ada override. Kode override tetap ada & bisa dihidupkan lagi.
 *
 * Tes memanggil recordForCompletedPo() LANGSUNG (bukan lewat complete()) untuk
 * mengisolasi logika komisi dari mekanik stok inter-partner.
 */
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

    /** PO subtotal PERSIS (qty 1, harga override) — routing diterapkan, TAK di-complete. */
    private function pendingPo(User $buyer, float $subtotal): PurchaseOrder
    {
        $p = $this->product();

        return $this->svc()->createForPartner($buyer, [['product_id' => $p->id, 'qty' => 1]], null, null, [$p->id => $subtotal]);
    }

    private function commissionFor(User $u, PurchaseOrder $po): float
    {
        return (float) Commission::where('user_id', $u->id)->where('source_po_id', $po->id)->sum('amount');
    }

    public function test_distri_route_ke_gd_tanpa_override(): void
    {
        // Distributor dgn upline-GD → PO inter-partner (seller_id=GD). Untung GD dari
        // MARGIN, bukan override → recordForCompletedPo skip → nol baris komisi.
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);

        $po = $this->pendingPo($dist, 1000000);
        $this->assertSame($grand->id, $po->seller_id); // routing ke GD

        $this->commissionSvc()->recordForCompletedPo($po);
        $this->assertSame(0, Commission::where('source_po_id', $po->id)->count());
    }

    public function test_override_padam_rate_nol_di_jalur_hq(): void
    {
        // Reseller (no-stock) → HQ (seller null). Punya upline TAPI rate override
        // default 0 → tetap nol komisi.
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $reseller = $this->user(User::ROLE_RESELLER_BRONZE, $dist->id);

        $po = $this->pendingPo($reseller, 100000);
        $this->assertNull($po->seller_id); // no-stock → HQ

        $this->commissionSvc()->recordForCompletedPo($po);
        $this->assertSame(0, Commission::where('source_po_id', $po->id)->count()); // rate 0
    }

    public function test_override_revivable_bila_rate_dihidupkan(): void
    {
        // Non-destruktif: kode override MASIH hidup. Set rate > 0 + PO jatuh ke HQ
        // (seller null) dgn upline → override cair lagi, tanpa build ulang.
        AppSetting::put('komisi_persen_grand_distributor', '6');
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);

        $po = $this->pendingPo($dist, 1000000);
        $po->seller_id = null; // paksa jalur HQ untuk menguji kode override langsung
        $po->save();

        $this->commissionSvc()->recordForCompletedPo($po->fresh());

        $this->assertEqualsWithDelta(60000, $this->commissionFor($grand, $po), 0.01); // 6% hidup lagi
        $this->assertEqualsWithDelta(60000, $this->commissionSvc()->balance($grand), 0.01);
        $this->assertSame('override', Commission::where('source_po_id', $po->id)->value('type'));
    }

    public function test_idempoten_tidak_dobel(): void
    {
        AppSetting::put('komisi_persen_grand_distributor', '6');
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);

        $po = $this->pendingPo($dist, 100000);
        $po->seller_id = null;
        $po->save();

        $this->commissionSvc()->recordForCompletedPo($po->fresh());
        $before = Commission::where('source_po_id', $po->id)->count();
        $this->assertGreaterThan(0, $before);

        $this->commissionSvc()->recordForCompletedPo($po->fresh());
        $this->assertSame($before, Commission::where('source_po_id', $po->id)->count()); // tidak dobel
    }

    public function test_pembeli_tanpa_upline_nol_komisi(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR); // upline null
        $po = $this->pendingPo($grand, 100000);
        $this->assertNull($po->seller_id);

        $this->commissionSvc()->recordForCompletedPo($po);
        $this->assertSame(0, Commission::where('source_po_id', $po->id)->count());
    }

    public function test_backdated_sale_tak_hasilkan_komisi(): void
    {
        // Buyer TANPA upline stockist → seller null (HQ), hindari kebutuhan stok GD.
        // recordCommission=false → nol komisi berapa pun rate-nya.
        AppSetting::put('komisi_persen_grand_distributor', '6');
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR); // no upline → HQ
        $admin = $this->user(User::ROLE_SUPER_ADMIN);
        $p = $this->product();

        $po = $this->svc()->recordBackdatedSale(
            $grand, [['product_id' => $p->id, 'qty' => 2]], Carbon::parse('2026-08-01'), 'backfill', $admin->id,
        );

        $this->assertSame(PurchaseOrder::STATUS_COMPLETED, $po->status);
        $this->assertSame(0, Commission::where('source_po_id', $po->id)->count());
    }
}
