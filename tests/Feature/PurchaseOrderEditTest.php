<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * Edit PO (item & qty) selagi pending & belum ada bukti bayar. Bangun ulang
 * baris + hitung ulang total di harga tier pembeli; nol efek stok/komisi.
 */
class PurchaseOrderEditTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $role): User
    {
        $u = 'u'.(++$this->seq);

        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'company_name' => 'CV '.$u, 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function product(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Produk '.(++$this->seq), 'sku' => 'SKU-'.$this->seq,
            'price_grand' => 22000, 'price_distributor' => 24000, 'price_reseller' => 29000, 'price_retail' => 39000,
            'cogs' => 10000, 'hq_stock' => 100, 'status' => 'active',
        ], $overrides));
    }

    private function svc(): PurchaseOrderService
    {
        return app(PurchaseOrderService::class);
    }

    private function pendingPo(User $buyer, array $lines): PurchaseOrder
    {
        return $this->svc()->createForPartner($buyer, $lines, null, null);
    }

    public function test_update_items_tambah_ubah_hapus_hitung_ulang(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $a = $this->product();
        $b = $this->product();
        $c = $this->product();

        $po = $this->pendingPo($grand, [
            ['product_id' => $a->id, 'qty' => 2],
            ['product_id' => $b->id, 'qty' => 1],
        ]);
        $this->assertEqualsWithDelta(3 * 22000, (float) $po->total_amount, 0.01); // 66000

        // a jadi 5, b dihapus (qty 0), c ditambah 3.
        $updated = $this->svc()->updateItems($po, [
            ['product_id' => $a->id, 'qty' => 5],
            ['product_id' => $b->id, 'qty' => 0],
            ['product_id' => $c->id, 'qty' => 3],
        ]);

        $qtyByProduct = $updated->items->pluck('qty', 'product_id');
        $this->assertSame(5, (int) $qtyByProduct[$a->id]);
        $this->assertFalse($qtyByProduct->has($b->id), 'produk b harusnya terhapus');
        $this->assertSame(3, (int) $qtyByProduct[$c->id]);
        $this->assertCount(2, $updated->items);
        $this->assertEqualsWithDelta((5 + 3) * 22000, (float) $updated->total_amount, 0.01); // 176000
    }

    public function test_is_editable_gate(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product();
        $po = $this->pendingPo($grand, [['product_id' => $p->id, 'qty' => 1]]);

        $this->assertTrue($po->isEditable());

        $po->payment_status = PurchaseOrder::PAYMENT_AWAITING;
        $this->assertFalse($po->isEditable(), 'terkunci begitu bukti bayar masuk');

        $po->payment_status = PurchaseOrder::PAYMENT_UNPAID;
        $po->status = PurchaseOrder::STATUS_PROCESSING;
        $this->assertFalse($po->isEditable(), 'terkunci begitu status naik');
    }

    public function test_update_items_ditolak_bila_tidak_editable(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product();
        $po = $this->pendingPo($grand, [['product_id' => $p->id, 'qty' => 2]]);
        $po->update(['payment_status' => PurchaseOrder::PAYMENT_AWAITING]);

        $this->expectException(RuntimeException::class);
        $this->svc()->updateItems($po, [['product_id' => $p->id, 'qty' => 9]]);
    }

    public function test_update_items_nol_efek_stok_dan_komisi(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product(['hq_stock' => 100]);
        $po = $this->pendingPo($grand, [['product_id' => $p->id, 'qty' => 2]]);

        $this->svc()->updateItems($po, [['product_id' => $p->id, 'qty' => 50]]);

        $this->assertSame(100, (int) $p->fresh()->hq_stock, 'stok HQ tak boleh tersentuh saat edit pending');
        $this->assertSame(0, Commission::count(), 'edit pending tak boleh memicu komisi');
    }

    public function test_update_items_pertahankan_diskon_dan_ongkir(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product();
        $po = $this->pendingPo($grand, [['product_id' => $p->id, 'qty' => 1]]);
        $po->update(['discount' => 5000, 'shipping_cost' => 10000]);

        $updated = $this->svc()->updateItems($po, [['product_id' => $p->id, 'qty' => 4]]);

        // subtotal 4*22000 = 88000; total = 88000 - 5000 + 10000 = 93000
        $this->assertEqualsWithDelta(88000, (float) $updated->subtotal, 0.01);
        $this->assertEqualsWithDelta(93000, (float) $updated->total_amount, 0.01);
    }

    public function test_owner_buka_form_edit_qty_prefill(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product();
        $po = $this->pendingPo($grand, [['product_id' => $p->id, 'qty' => 7]]);

        $this->actingAs($grand)->get(route('purchase-orders.edit', $po))
            ->assertOk()
            ->assertSee('Simpan Perubahan')
            ->assertSee('value="7"', false); // qty ter-prefill di form
    }

    public function test_owner_submit_update(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $a = $this->product();
        $b = $this->product();
        $po = $this->pendingPo($grand, [['product_id' => $a->id, 'qty' => 2]]);

        $this->actingAs($grand)->put(route('purchase-orders.update', $po), [
            'items' => [
                ['product_id' => $a->id, 'qty' => 3],
                ['product_id' => $b->id, 'qty' => 1],
            ],
        ])->assertRedirect(route('purchase-orders.show', $po));

        $po->refresh()->load('items');
        $this->assertCount(2, $po->items);
        $this->assertEqualsWithDelta((3 + 1) * 22000, (float) $po->total_amount, 0.01);
    }

    public function test_admin_edit_po_mitra(): void
    {
        $admin = $this->user(User::ROLE_SUPER_ADMIN);
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product();
        $po = $this->pendingPo($grand, [['product_id' => $p->id, 'qty' => 1]]);

        $this->actingAs($admin)->put(route('purchase-orders.update', $po), [
            'items' => [['product_id' => $p->id, 'qty' => 10]],
        ])->assertRedirect();

        $this->assertSame(10, (int) $po->refresh()->items()->first()->qty);
    }

    public function test_mitra_lain_tak_boleh_edit(): void
    {
        $owner = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $lain = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product();
        $po = $this->pendingPo($owner, [['product_id' => $p->id, 'qty' => 1]]);

        $this->actingAs($lain)->get(route('purchase-orders.edit', $po))->assertForbidden();
        $this->actingAs($lain)->put(route('purchase-orders.update', $po), [
            'items' => [['product_id' => $p->id, 'qty' => 99]],
        ])->assertForbidden();

        $this->assertSame(1, (int) $po->refresh()->items()->first()->qty); // tak berubah
    }

    public function test_update_http_ditolak_saat_terkunci(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product();
        $po = $this->pendingPo($grand, [['product_id' => $p->id, 'qty' => 2]]);
        $po->update(['payment_status' => PurchaseOrder::PAYMENT_AWAITING]);

        $this->actingAs($grand)->from(route('purchase-orders.show', $po))
            ->put(route('purchase-orders.update', $po), [
                'items' => [['product_id' => $p->id, 'qty' => 50]],
            ])
            ->assertRedirect(route('purchase-orders.show', $po))
            ->assertSessionHasErrors('status');

        $this->assertSame(2, (int) $po->refresh()->items()->first()->qty); // tak berubah
    }

    public function test_tombol_edit_muncul_editable_hilang_saat_terkunci(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product();
        $po = $this->pendingPo($grand, [['product_id' => $p->id, 'qty' => 1]]);

        $this->actingAs($grand)->get(route('purchase-orders.show', $po))
            ->assertOk()->assertSee('Edit PO');

        $po->update(['payment_status' => PurchaseOrder::PAYMENT_AWAITING]);
        $this->actingAs($grand)->get(route('purchase-orders.show', $po))
            ->assertOk()->assertDontSee('Edit PO');
    }
}
