<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Inventory;
use App\Models\PoReturn;
use App\Models\PoReturnItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\VolumeIncentiveTier;
use App\Services\CommissionService;
use App\Services\PurchaseOrderService;
use App\Services\ReturService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * Retur PO Fase 1 (engine): reversal stok + clawback komisi (ro_cashback propor-
 * sional + volume re-eval) + write-off rusak + guard over-return. Money-critical.
 */
class ReturTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $role, array $extra = []): User
    {
        $u = 'u'.(++$this->seq);

        return User::create(array_merge([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@t.test",
            'password' => Hash::make('secret123'), 'company_name' => 'CV '.$u, 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ], $extra));
    }

    private function product(int $stock = 1000): Product
    {
        return Product::create([
            'name' => 'P'.(++$this->seq), 'sku' => 'SKU-'.$this->seq,
            'price_grand' => 22000, 'price_distributor' => 24000, 'price_reseller' => 29000, 'price_retail' => 39000,
            'cogs' => 10000, 'hq_stock' => $stock, 'status' => Product::STATUS_ACTIVE,
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

    private function partnerStock(User $u, Product $p): int
    {
        return (int) Inventory::where('user_id', $u->id)->where('product_id', $p->id)->value('quantity');
    }

    private function volumeBalance(User $g): float
    {
        return (float) Commission::where('user_id', $g->id)->where('type', 'volume_bonus')->sum('amount');
    }

    private function retur(PurchaseOrder $po, array $itemQtys, string $kondisi = 'normal'): PoReturn
    {
        $retur = PoReturn::create(['purchase_order_id' => $po->id, 'status' => 'pending', 'kondisi' => $kondisi]);
        foreach ($itemQtys as [$poItemId, $qty]) {
            PoReturnItem::create(['po_return_id' => $retur->id, 'purchase_order_item_id' => $poItemId, 'qty' => $qty]);
        }

        return $retur;
    }

    public function test_retur_hq_balikin_stok_dan_clawback_ro_cashback(): void
    {
        $sponsor = $this->user(User::ROLE_SPONSOR);
        $gd = $this->user(User::ROLE_GRAND_DISTRIBUTOR, ['sponsor_id' => $sponsor->id]);
        $p = $this->product(1000);

        // GD restock 100 @ 22rb → subtotal 2,2jt. RO cashback 5% = 110rb ke sponsor.
        $po = $this->svc()->createForPartner($gd, [['product_id' => $p->id, 'qty' => 100]], null, null);
        $this->svc()->complete($po);
        $po->refresh();

        $this->assertSame(900, (int) $p->fresh()->hq_stock);   // HQ -100
        $this->assertSame(100, $this->partnerStock($gd, $p));   // GD +100
        $roBefore = $this->commissionSvc()->balance($sponsor);
        $this->assertEqualsWithDelta(110_000, $roBefore, 0.01); // 5% × 2,2jt

        // Retur 40 dari 100 (40%) — normal.
        app(ReturService::class)->apply($this->retur($po, [[$po->items->first()->id, 40]], 'normal'));

        $this->assertSame(940, (int) $p->fresh()->hq_stock);   // HQ balik +40
        $this->assertSame(60, $this->partnerStock($gd, $p));    // GD -40
        $this->assertEqualsWithDelta(66_000, $this->commissionSvc()->balance($sponsor), 0.01); // 110rb × 60%
    }

    public function test_retur_rusak_write_off_hq_tak_nambah(): void
    {
        $gd = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product(1000);

        $po = $this->svc()->createForPartner($gd, [['product_id' => $p->id, 'qty' => 50]], null, null);
        $this->svc()->complete($po);
        $this->assertSame(950, (int) $p->fresh()->hq_stock);

        // Retur 20 RUSAK → GD turun, HQ TAK nambah (write-off).
        app(ReturService::class)->apply($this->retur($po, [[$po->items->first()->id, 20]], 'rusak'));

        $this->assertSame(950, (int) $p->fresh()->hq_stock);   // HQ tetap (write-off)
        $this->assertSame(30, $this->partnerStock($gd, $p));    // GD -20
    }

    public function test_retur_volume_clawback(): void
    {
        VolumeIncentiveTier::create(['threshold' => 200_000_000, 'rate_percent' => 5, 'is_active' => true]);
        $gd = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product(100);

        // Belanja 250jt (qty 1, harga override) → volume 5% = 12,5jt.
        $po = $this->svc()->createForPartner($gd, [['product_id' => $p->id, 'qty' => 1]], null, null, [$p->id => 250_000_000]);
        $this->svc()->complete($po);
        $this->assertEqualsWithDelta(12_500_000, $this->volumeBalance($gd), 0.01);

        // Retur penuh → netTotal 0 → clawback penuh.
        app(ReturService::class)->apply($this->retur($po, [[$po->fresh()->items->first()->id, 1]], 'normal'));
        $this->assertEqualsWithDelta(0, $this->volumeBalance($gd), 0.01);
    }

    public function test_over_return_ditolak(): void
    {
        $gd = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product(1000);
        $po = $this->svc()->createForPartner($gd, [['product_id' => $p->id, 'qty' => 10]], null, null);
        $this->svc()->complete($po);

        $this->expectException(RuntimeException::class);
        app(ReturService::class)->apply($this->retur($po, [[$po->items->first()->id, 15]], 'normal')); // > 10
    }
}
