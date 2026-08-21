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

/**
 * Sponsor Fase 2 — RO cashback: tiap GD restock ke HQ, PEREKRUT (sponsor_id) dapat
 * 5% omzet (income pasif). Hanya GD memicu; tanpa perekrut → nol. Rate configurable.
 * Tes panggil recordForCompletedPo() langsung utk isolasi dari stok.
 */
class RoCashbackTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $role, array $extra = []): User
    {
        $u = 'u'.(++$this->seq);

        return User::create(array_merge([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'company_name' => 'CV '.$u, 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ], $extra));
    }

    private function product(): Product
    {
        return Product::create([
            'name' => 'P'.(++$this->seq), 'sku' => 'SKU-'.$this->seq,
            'price_grand' => 22000, 'price_distributor' => 24000, 'price_reseller' => 29000, 'price_retail' => 39000,
            'cogs' => 10000, 'hq_stock' => 1000, 'status' => Product::STATUS_ACTIVE,
        ]);
    }

    private function commissionSvc(): CommissionService
    {
        return app(CommissionService::class);
    }

    private function hqPo(User $buyer, float $subtotal): PurchaseOrder
    {
        $p = $this->product();

        return app(PurchaseOrderService::class)->createForPartner($buyer, [['product_id' => $p->id, 'qty' => 1]], null, null, [$p->id => $subtotal]);
    }

    private function cashbackFor(User $u, PurchaseOrder $po): float
    {
        return (float) Commission::where('user_id', $u->id)->where('source_po_id', $po->id)->where('type', 'ro_cashback')->sum('amount');
    }

    public function test_gd_restock_perekrut_dapat_5_persen(): void
    {
        $sponsor = $this->user(User::ROLE_SPONSOR);
        $gd = $this->user(User::ROLE_GRAND_DISTRIBUTOR, ['sponsor_id' => $sponsor->id]);

        $po = $this->hqPo($gd, 1000000);
        $this->assertNull($po->seller_id); // GD → HQ

        $this->commissionSvc()->recordForCompletedPo($po);

        $this->assertEqualsWithDelta(50000, $this->cashbackFor($sponsor, $po), 0.01); // 5% dari 1jt
        $this->assertSame('ro_cashback', Commission::where('source_po_id', $po->id)->value('type'));
    }

    public function test_gd_tanpa_perekrut_nol(): void
    {
        $gd = $this->user(User::ROLE_GRAND_DISTRIBUTOR); // sponsor null
        $po = $this->hqPo($gd, 1000000);

        $this->commissionSvc()->recordForCompletedPo($po);
        $this->assertSame(0, Commission::where('source_po_id', $po->id)->count());
    }

    public function test_hanya_gd_yang_memicu_distri_nol(): void
    {
        $sponsor = $this->user(User::ROLE_SPONSOR);
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, ['upline_id' => $grand->id, 'sponsor_id' => $sponsor->id]);

        // Paksa jalur HQ (seller null) untuk menguji: distri bukan GD → tak ada RO cashback.
        $po = $this->hqPo($dist, 1000000);
        $po->seller_id = null;
        $po->save();

        $this->commissionSvc()->recordForCompletedPo($po->fresh());
        $this->assertSame(0, Commission::where('source_po_id', $po->id)->count()); // bukan GD + override dorman
    }

    public function test_rate_configurable(): void
    {
        AppSetting::put('komisi_persen_ro_cashback', '8');
        $sponsor = $this->user(User::ROLE_SPONSOR);
        $gd = $this->user(User::ROLE_GRAND_DISTRIBUTOR, ['sponsor_id' => $sponsor->id]);

        $po = $this->hqPo($gd, 1000000);
        $this->commissionSvc()->recordForCompletedPo($po);

        $this->assertEqualsWithDelta(80000, $this->cashbackFor($sponsor, $po), 0.01); // 8%
    }

    public function test_idempoten(): void
    {
        $sponsor = $this->user(User::ROLE_SPONSOR);
        $gd = $this->user(User::ROLE_GRAND_DISTRIBUTOR, ['sponsor_id' => $sponsor->id]);

        $po = $this->hqPo($gd, 1000000);
        $this->commissionSvc()->recordForCompletedPo($po);
        $before = Commission::where('source_po_id', $po->id)->count();
        $this->assertGreaterThan(0, $before);

        $this->commissionSvc()->recordForCompletedPo($po->fresh());
        $this->assertSame($before, Commission::where('source_po_id', $po->id)->count()); // tak dobel
    }
}
