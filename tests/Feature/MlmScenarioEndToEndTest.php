<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Inventory;
use App\Models\JoinPackage;
use App\Models\PoReturn;
use App\Models\PoReturnItem;
use App\Models\Product;
use App\Models\User;
use App\Models\VolumeIncentiveTier;
use App\Services\OnboardingService;
use App\Services\PurchaseOrderService;
use App\Services\ReportService;
use App\Services\ReturService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Skenario MLM MENYELURUH (satu alur, banyak fitur) — bukti bahwa Model A +
 * Sponsor + Volume + Pesanan Downline + Laporan + Retur nyambung end-to-end.
 * Rantai: HQ → Grand (Nur) → Distributor. Sponsor = Nur.
 */
class MlmScenarioEndToEndTest extends TestCase
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

    private function product(): Product
    {
        return Product::create([
            'name' => 'Sabun', 'sku' => 'SKU-'.(++$this->seq),
            'price_grand' => 22000, 'price_distributor' => 24000, 'price_reseller' => 29000, 'price_retail' => 39000,
            'cogs' => 10000, 'hq_stock' => 1000, 'status' => Product::STATUS_ACTIVE,
        ]);
    }

    private function svc(): PurchaseOrderService
    {
        return app(PurchaseOrderService::class);
    }

    private function stock(User $u, Product $p): int
    {
        return (int) Inventory::where('user_id', $u->id)->where('product_id', $p->id)->value('quantity');
    }

    private function komisi(User $u, string $type): float
    {
        return (float) Commission::where('user_id', $u->id)->where('type', $type)->sum('amount');
    }

    public function test_alur_penuh_hq_grand_distributor(): void
    {
        // ===== SETUP jaringan =====
        VolumeIncentiveTier::create(['threshold' => 2_000_000, 'rate_percent' => 5, 'is_active' => true]);
        $admin = $this->user(User::ROLE_SUPER_ADMIN);
        $sponsor = $this->user(User::ROLE_DISTRIBUTOR);                              // perekrut
        $nur = $this->user(User::ROLE_GRAND_DISTRIBUTOR, ['sponsor_id' => $sponsor->id]);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, ['upline_id' => $nur->id]);      // downline pasok Nur
        $p = $this->product();

        // ===== STEP 1: Grand (Nur) beli ke HQ, 100 unit @ 22rb = 2,2jt =====
        $po1 = $this->svc()->createForPartner($nur, [['product_id' => $p->id, 'qty' => 100]], null, null);
        $this->assertNull($po1->seller_id, 'PO Grand→HQ: seller null');
        $this->assertEqualsWithDelta(2_200_000, (float) $po1->total_amount, 0.01);
        $this->svc()->complete($po1);

        $this->assertSame(900, (int) $p->fresh()->hq_stock);   // HQ -100
        $this->assertSame(100, $this->stock($nur, $p));         // Nur +100
        $this->assertEqualsWithDelta(110_000, $this->komisi($sponsor, 'ro_cashback'), 0.01); // 5% × 2,2jt → sponsor
        $this->assertEqualsWithDelta(110_000, $this->komisi($nur, 'volume_bonus'), 0.01);    // volume 5% × 2,2jt → Nur

        // ===== STEP 2: Distributor beli ke Nur (inter-partner AUTO-route), 30 @ 24rb =====
        $po2 = $this->svc()->createForPartner($dist, [['product_id' => $p->id, 'qty' => 30]], null, null);
        $this->assertSame($nur->id, $po2->seller_id, 'PO Distri→Grand: seller = Nur (Model A live)');
        $this->assertEqualsWithDelta(720_000, (float) $po2->total_amount, 0.01); // harga distributor
        $this->svc()->complete($po2);

        $this->assertSame(70, $this->stock($nur, $p));          // Nur -30 → 70
        $this->assertSame(30, $this->stock($dist, $p));         // Distri +30
        $this->assertSame(900, (int) $p->fresh()->hq_stock);    // HQ TAK tersentuh (inter-partner)
        $this->assertEqualsWithDelta(110_000, $this->komisi($nur, 'volume_bonus'), 0.01);    // volume TAK nambah (inter-partner)
        $this->assertEqualsWithDelta(110_000, $this->komisi($sponsor, 'ro_cashback'), 0.01); // RO cashback TAK nambah

        // ===== STEP 3: Laporan — Penjualan ke Downline =====
        $report = app(ReportService::class);
        $this->assertEqualsWithDelta(720_000, (float) $report->summary($nur)['downline_sales'], 0.01);
        $this->assertSame(0.0, (float) $report->summary($dist)['downline_sales']); // pembeli, bukan penjual

        // ===== STEP 4: Onboarding reseller (upline Distri, SPONSOR Nur) → bonus join ke Nur =====
        $paket = JoinPackage::create(['name' => 'Bronze', 'target_role' => User::ROLE_RESELLER_BRONZE, 'price' => 149000, 'is_active' => true]);
        $paket->items()->create(['product_id' => $p->id, 'qty' => 2]);
        app(OnboardingService::class)->onboard(
            ['fullname' => 'Reseller1', 'email' => 'r1@t.test', 'username' => 'r1', 'password' => 'secret123'],
            $paket, $dist->id, $admin->id, $nur->id,
        );
        $this->assertSame(898, (int) $p->fresh()->hq_stock);   // paket potong HQ -2
        $this->assertEqualsWithDelta(14_900, $this->komisi($nur, 'join'), 0.01); // 10% × 149rb → Nur (sponsor)

        // ===== STEP 5: Retur — Distributor retur 10 dari 30 ke Nur (normal) =====
        $retur = PoReturn::create(['purchase_order_id' => $po2->id, 'status' => 'pending', 'kondisi' => 'normal']);
        PoReturnItem::create(['po_return_id' => $retur->id, 'purchase_order_item_id' => $po2->items->first()->id, 'qty' => 10]);
        app(ReturService::class)->apply($retur);

        $this->assertSame(20, $this->stock($dist, $p));         // Distri -10 → 20
        $this->assertSame(80, $this->stock($nur, $p));          // Nur +10 (barang balik) → 80
        // Penjualan ke Downline jadi NET: 720rb − (10×24rb retur) = 480rb.
        $this->assertEqualsWithDelta(480_000, (float) $report->summary($nur)['downline_sales'], 0.01);

        // ===== STEP 6: Dashboard Grand — label + kartu Model A =====
        $html = $this->actingAs($nur)->get('/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('Belanja ke HQ', $html);          // relabel mitra
        $this->assertStringContainsString('Penjualan ke Downline', $html);  // kartu baru
    }
}
