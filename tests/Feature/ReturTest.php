<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Inventory;
use App\Models\JoinPackage;
use App\Models\JoinTransaction;
use App\Models\PoReturn;
use App\Models\PoReturnItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\VolumeIncentiveTier;
use App\Services\CommissionService;
use App\Services\InventoryService;
use App\Services\OnboardingService;
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

    public function test_retur_from_customer_lewati_stok_mitra_dan_void_simetris(): void
    {
        $gd = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product(1000);

        $po = $this->svc()->createForPartner($gd, [['product_id' => $p->id, 'qty' => 100]], null, null);
        $this->svc()->complete($po);
        $po->refresh();

        // GD jual habis stoknya (barang sudah keluar dari sistem) → stok mitra 0.
        app(InventoryService::class)->setPartnerStock($gd->id, $p->id, 0);
        $this->assertSame(0, $this->partnerStock($gd, $p));
        $this->assertSame(900, (int) $p->fresh()->hq_stock);

        // Retur 40 "dari pelanggan" — TAK boleh gagal walau stok mitra 0.
        $retur = PoReturn::create([
            'purchase_order_id' => $po->id, 'status' => 'pending', 'kondisi' => 'normal', 'from_customer' => true,
        ]);
        PoReturnItem::create(['po_return_id' => $retur->id, 'purchase_order_item_id' => $po->items->first()->id, 'qty' => 40]);
        app(ReturService::class)->apply($retur);

        $this->assertSame(0, $this->partnerStock($gd, $p));    // stok mitra TETAP 0 (tak disentuh)
        $this->assertSame(940, (int) $p->fresh()->hq_stock);   // HQ tetap restock +40

        // Void → HQ balik 900, stok mitra tetap 0 (dulu tak disentuh, jadi tak ditambah).
        app(ReturService::class)->void($retur->fresh());
        $this->assertSame(0, $this->partnerStock($gd, $p));
        $this->assertSame(900, (int) $p->fresh()->hq_stock);
    }

    public function test_retur_biasa_stok_mitra_habis_tetap_ditolak(): void
    {
        // Tanpa from_customer, guard lama tetap berlaku: stok mitra tak cukup → gagal.
        $gd = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product(1000);
        $po = $this->svc()->createForPartner($gd, [['product_id' => $p->id, 'qty' => 100]], null, null);
        $this->svc()->complete($po);
        $po->refresh();
        app(InventoryService::class)->setPartnerStock($gd->id, $p->id, 0);

        $retur = PoReturn::create([
            'purchase_order_id' => $po->id, 'status' => 'pending', 'kondisi' => 'normal', 'from_customer' => false,
        ]);
        PoReturnItem::create(['po_return_id' => $retur->id, 'purchase_order_item_id' => $po->items->first()->id, 'qty' => 40]);

        $this->expectException(RuntimeException::class);
        app(ReturService::class)->apply($retur);
    }

    public function test_retur_potong_sisa_tagihan(): void
    {
        $gd = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product(1000); // price_grand 22.000
        $po = $this->svc()->createForPartner($gd, [['product_id' => $p->id, 'qty' => 100]], null, null); // 2.200.000
        $this->svc()->complete($po);
        $po->refresh();

        $this->assertEqualsWithDelta(2_200_000, $po->remaining(), 0.01);
        $this->assertFalse($po->isSettled());

        // Retur 40 @ 22.000 = 880.000 → sisa tagihan turun jadi 1.320.000.
        app(ReturService::class)->apply($this->retur($po, [[$po->items->first()->id, 40]]));
        $po->refresh();

        $this->assertEqualsWithDelta(880_000, $po->returnsCredit(), 0.01);
        $this->assertEqualsWithDelta(1_320_000, $po->remaining(), 0.01);
    }

    public function test_retur_penuh_bikin_lunas_dan_kelebihan_jadi_refund(): void
    {
        $gd = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product(1000);
        $po = $this->svc()->createForPartner($gd, [['product_id' => $p->id, 'qty' => 10]], null, null); // 220.000
        $this->svc()->complete($po);
        $po->refresh();

        // Bayar sebagian 100.000 → sisa 120.000.
        $po->payments()->create(['amount' => 100_000, 'paid_at' => now(), 'notes' => null, 'created_by' => $gd->id]);
        $po->refresh();
        $this->assertEqualsWithDelta(120_000, $po->remaining(), 0.01);

        // Retur penuh 10 = 220.000. Bayar 100k + retur 220k = 320k > total 220k →
        // lunas, sisa 0, kelebihan 100k jadi refund ke pembeli.
        app(ReturService::class)->apply($this->retur($po, [[$po->items->first()->id, 10]]));
        $po->refresh();

        $this->assertTrue($po->isSettled());
        $this->assertEqualsWithDelta(0, $po->remaining(), 0.01);
        $this->assertEqualsWithDelta(100_000, $po->refundDue(), 0.01);
    }

    public function test_void_retur_kembalikan_sisa_tagihan(): void
    {
        $gd = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product(1000);
        $po = $this->svc()->createForPartner($gd, [['product_id' => $p->id, 'qty' => 100]], null, null);
        $this->svc()->complete($po);
        $po->refresh();

        $retur = $this->retur($po, [[$po->items->first()->id, 40]]);
        app(ReturService::class)->apply($retur);
        $this->assertEqualsWithDelta(1_320_000, $po->fresh()->remaining(), 0.01);

        // Void → potongan retur hilang (status void tak dihitung) → sisa balik penuh.
        app(ReturService::class)->void($retur->fresh());
        $this->assertEqualsWithDelta(2_200_000, $po->fresh()->remaining(), 0.01);
    }

    public function test_super_admin_hapus_permanen_retur_rejected(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN);
        $gd = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product(1000);
        $po = $this->svc()->createForPartner($gd, [['product_id' => $p->id, 'qty' => 100]], null, null);
        $this->svc()->complete($po);
        $po->refresh();

        $retur = $this->retur($po, [[$po->items->first()->id, 40]]);
        $retur->update(['status' => 'rejected']);

        $this->actingAs($super)->delete(route('retur.force-destroy', $retur))->assertRedirect();

        $this->assertDatabaseMissing('po_returns', ['id' => $retur->id]);
        $this->assertDatabaseMissing('po_return_items', ['po_return_id' => $retur->id]);
    }

    public function test_hapus_permanen_retur_applied_ditolak(): void
    {
        // Retur Applied HARUS di-batalkan dulu — tak boleh dihapus langsung.
        $super = $this->user(User::ROLE_SUPER_ADMIN);
        $gd = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product(1000);
        $po = $this->svc()->createForPartner($gd, [['product_id' => $p->id, 'qty' => 100]], null, null);
        $this->svc()->complete($po);
        $po->refresh();

        $retur = $this->retur($po, [[$po->items->first()->id, 40]]);
        app(ReturService::class)->apply($retur);

        $this->actingAs($super)->delete(route('retur.force-destroy', $retur))->assertRedirect();

        $this->assertDatabaseHas('po_returns', ['id' => $retur->id, 'status' => 'applied']);
    }

    public function test_non_super_admin_tak_bisa_hapus_permanen_retur(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $gd = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product(1000);
        $po = $this->svc()->createForPartner($gd, [['product_id' => $p->id, 'qty' => 100]], null, null);
        $this->svc()->complete($po);
        $po->refresh();

        $retur = $this->retur($po, [[$po->items->first()->id, 40]]);
        $retur->update(['status' => 'rejected']);

        $this->actingAs($admin)->delete(route('retur.force-destroy', $retur))->assertForbidden();
        $this->assertDatabaseHas('po_returns', ['id' => $retur->id]);
    }

    public function test_retur_index_menampilkan_barang_diretur(): void
    {
        // Approver (process_return) harus bisa LIHAT barang apa yang diretur +
        // qty-nya di daftar, bukan cuma setuju/tolak buta.
        $admin = $this->user(User::ROLE_ADMIN);
        $gd = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product(1000);

        $po = $this->svc()->createForPartner($gd, [['product_id' => $p->id, 'qty' => 100]], null, null);
        $this->svc()->complete($po);
        $po->refresh();

        $this->retur($po, [[$po->items->first()->id, 40]], 'normal'); // pending

        $this->actingAs($admin)->get('/retur')
            ->assertOk()
            ->assertSee('Barang Diretur')
            ->assertSee($p->name)
            ->assertSee('×40');
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

    public function test_hq_input_retur_langsung_applied(): void
    {
        $admin = $this->user(User::ROLE_SUPER_ADMIN); // process_return (super_admin bypass)
        $gd = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product(1000);
        $po = $this->svc()->createForPartner($gd, [['product_id' => $p->id, 'qty' => 100]], null, null);
        $this->svc()->complete($po);
        $po->refresh();

        $this->actingAs($admin)->post(route('retur.store'), [
            'purchase_order_id' => $po->id, 'kondisi' => 'normal',
            'items' => [['po_item_id' => $po->items->first()->id, 'qty' => 30]],
        ])->assertRedirect(route('retur.index'));

        $this->assertSame('applied', PoReturn::first()->status);
        $this->assertSame(70, $this->partnerStock($gd, $p)); // 100 - 30 berlaku
    }

    public function test_mitra_input_pending_lalu_hq_acc(): void
    {
        $admin = $this->user(User::ROLE_SUPER_ADMIN);
        $gd = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product(1000);
        $po = $this->svc()->createForPartner($gd, [['product_id' => $p->id, 'qty' => 100]], null, null);
        $this->svc()->complete($po);
        $po->refresh();

        // Mitra (pembeli) ajukan → pending, belum berlaku.
        $this->actingAs($gd)->post(route('retur.store'), [
            'purchase_order_id' => $po->id, 'kondisi' => 'normal',
            'items' => [['po_item_id' => $po->items->first()->id, 'qty' => 30]],
        ])->assertRedirect(route('retur.index'));

        $retur = PoReturn::first();
        $this->assertSame('pending', $retur->status);
        $this->assertSame(100, $this->partnerStock($gd, $p)); // belum berlaku

        // HQ acc → applied.
        $this->actingAs($admin)->post(route('retur.approve', $retur))->assertRedirect();
        $this->assertSame('applied', $retur->fresh()->status);
        $this->assertSame(70, $this->partnerStock($gd, $p)); // sekarang berlaku
    }

    public function test_mitra_lain_tak_bisa_retur_po_orang(): void
    {
        $gd = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $lain = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product(1000);
        $po = $this->svc()->createForPartner($gd, [['product_id' => $p->id, 'qty' => 10]], null, null);
        $this->svc()->complete($po);

        $this->actingAs($lain)->get(route('retur.create', $po))->assertForbidden();
    }

    public function test_void_balikin_stok_dan_komisi(): void
    {
        $sponsor = $this->user(User::ROLE_SPONSOR);
        $gd = $this->user(User::ROLE_GRAND_DISTRIBUTOR, ['sponsor_id' => $sponsor->id]);
        $p = $this->product(1000);
        $po = $this->svc()->createForPartner($gd, [['product_id' => $p->id, 'qty' => 100]], null, null);
        $this->svc()->complete($po);
        $po->refresh();

        $retur = $this->retur($po, [[$po->items->first()->id, 40]], 'normal');
        app(ReturService::class)->apply($retur);
        $this->assertSame(940, (int) $p->fresh()->hq_stock);   // retur normal: HQ +40
        $this->assertEqualsWithDelta(66_000, $this->commissionSvc()->balance($sponsor), 0.01); // clawback

        // Void → semua balik ke kondisi pasca-complete (sebelum retur).
        app(ReturService::class)->void($retur->fresh());
        $this->assertSame('void', $retur->fresh()->status);
        $this->assertSame(900, (int) $p->fresh()->hq_stock);   // +40 dibatalkan → 900
        $this->assertSame(100, $this->partnerStock($gd, $p));   // GD balik ke 100
        $this->assertEqualsWithDelta(110_000, $this->commissionSvc()->balance($sponsor), 0.01); // komisi balik
    }

    public function test_cancel_join_clawback_dan_balikin_stok_paket(): void
    {
        $sponsor = $this->user(User::ROLE_DISTRIBUTOR);
        $admin = $this->user(User::ROLE_SUPER_ADMIN);
        $p = $this->product(1000);
        $paket = JoinPackage::create(['name' => 'Bronze', 'target_role' => User::ROLE_RESELLER_BRONZE, 'price' => 149000, 'is_active' => true]);
        $paket->items()->create(['product_id' => $p->id, 'qty' => 5]);

        $member = app(OnboardingService::class)->onboard(
            ['fullname' => 'M1', 'email' => 'm1@t.test', 'username' => 'm1', 'password' => 'secret123'],
            $paket, null, $admin->id, $sponsor->id,
        );

        $this->assertSame(995, (int) $p->fresh()->hq_stock);   // -5 paket
        $this->assertEqualsWithDelta(14900, $this->commissionSvc()->balance($sponsor), 0.01); // 10% × 149rb ke sponsor

        // Batal join → clawback bonus + balikin stok paket.
        $trx = JoinTransaction::where('user_id', $member->id)->firstOrFail();
        app(ReturService::class)->cancelJoin($trx);

        $this->assertNotNull($trx->fresh()->cancelled_at);
        $this->assertSame(1000, (int) $p->fresh()->hq_stock);  // stok paket balik ke HQ
        $this->assertEqualsWithDelta(0, $this->commissionSvc()->balance($sponsor), 0.01); // bonus join ditarik
    }

    public function test_tombol_batal_join_muncul_lalu_hilang_setelah_batal(): void
    {
        $admin = $this->user(User::ROLE_SUPER_ADMIN);
        $p = $this->product(1000);
        $paket = JoinPackage::create(['name' => 'Bronze', 'target_role' => User::ROLE_RESELLER_BRONZE, 'price' => 149000, 'is_active' => true]);
        $paket->items()->create(['product_id' => $p->id, 'qty' => 5]);

        $member = app(OnboardingService::class)->onboard(
            ['fullname' => 'M1', 'email' => 'm1@t.test', 'username' => 'm1', 'password' => 'secret123'],
            $paket, null, $admin->id, null,
        );

        // Member punya join aktif → tombol "Batal Join" tampil di Kelola Anggota.
        $this->actingAs($admin)->get(route('users.index'))->assertOk()->assertSee('Batal Join');

        // Setelah dibatalkan → activeJoinTransaction null → tombol hilang.
        app(ReturService::class)->cancelJoin(JoinTransaction::where('user_id', $member->id)->firstOrFail());
        $this->actingAs($admin)->get(route('users.index'))->assertOk()->assertDontSee('Batal Join');
    }
}
