<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Model A Fase 3: PO distri→GD (inter-partner) menampilkan rekening GD ke distri
 * di halaman PO supaya tahu transfer ke mana. PO ke HQ (seller null) tidak.
 */
class InterPartnerPaymentInfoTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $role, ?int $upline = null, array $extra = []): User
    {
        $u = 'u'.(++$this->seq);

        return User::create(array_merge([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'company_name' => 'CV '.$u, 'role' => $role,
            'status' => User::STATUS_ACTIVE, 'upline_id' => $upline,
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

    private function poFor(User $buyer): PurchaseOrder
    {
        $p = $this->product();

        return app(PurchaseOrderService::class)->createForPartner($buyer, [['product_id' => $p->id, 'qty' => 1]], null, null);
    }

    public function test_distri_lihat_rekening_gd_di_po_inter_partner(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR, null, [
            'bank' => 'BCA', 'no_rekening' => '1234567890', 'atas_nama' => 'Budi Grand',
        ]);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);

        $po = $this->poFor($dist);
        $this->assertSame($grand->id, $po->seller_id); // inter-partner (routing Fase 1)

        $this->actingAs($dist)->get(route('purchase-orders.show', $po))
            ->assertOk()
            ->assertSee('rekening penjual (GD)')
            ->assertSee('BCA')
            ->assertSee('1234567890')
            ->assertSee('Budi Grand');
    }

    public function test_po_hq_tak_tampilkan_rekening_gd(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR); // upline null → seller null (HQ)
        $po = $this->poFor($grand);
        $this->assertNull($po->seller_id);

        $this->actingAs($grand)->get(route('purchase-orders.show', $po))
            ->assertOk()
            ->assertDontSee('rekening penjual (GD)');
    }

    public function test_gd_tanpa_rekening_tampilkan_peringatan(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR); // tanpa rekening
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);

        $po = $this->poFor($dist);

        $this->actingAs($dist)->get(route('purchase-orders.show', $po))
            ->assertOk()
            ->assertSee('Rekening penjual belum diisi');
    }
}
