<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PoQuickAndFilterTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $role): User
    {
        $u = 'u'.(++$this->seq);

        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@t.test",
            'password' => Hash::make('secret123'), 'company_name' => 'CV '.$u, 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function product(string $name): Product
    {
        return Product::create([
            'name' => $name, 'sku' => 'SKU-'.(++$this->seq),
            'price_grand' => 22000, 'price_distributor' => 24000, 'price_reseller' => 29000, 'price_retail' => 39000,
            'cogs' => 10000, 'hq_stock' => 1000, 'status' => Product::STATUS_ACTIVE,
        ]);
    }

    private function svc(): PurchaseOrderService
    {
        return app(PurchaseOrderService::class);
    }

    public function test_index_render_dengan_filter_produk_dan_modal(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->product('Face Mist');

        $this->actingAs($admin)->get('/purchase-orders')
            ->assertOk()
            ->assertSee('Semua Produk')      // dropdown filter produk
            ->assertSee('Face Mist')         // produk terdaftar di dropdown
            ->assertSee('poQuickModal');     // markup popup ada
    }

    public function test_quick_kembalikan_item_dan_sisa_retur(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $gd = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product('Face Mist');
        $po = $this->svc()->createForPartner($gd, [['product_id' => $p->id, 'qty' => 20]], null, null);

        $this->actingAs($admin)->getJson("/purchase-orders/{$po->id}/quick")
            ->assertOk()
            ->assertJsonPath('po_number', $po->po_number)
            ->assertJsonPath('items.0.product_name', 'Face Mist')
            ->assertJsonPath('items.0.qty', 20)
            ->assertJsonPath('items.0.returnable', 20);
    }

    public function test_filter_produk_menyaring_daftar(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $gd = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $mist = $this->product('Face Mist');
        $cream = $this->product('Day Cream');
        $poMist = $this->svc()->createForPartner($gd, [['product_id' => $mist->id, 'qty' => 5]], null, null);
        $poCream = $this->svc()->createForPartner($gd, [['product_id' => $cream->id, 'qty' => 5]], null, null);

        $this->actingAs($admin)->get('/purchase-orders?product='.$mist->id)
            ->assertOk()
            ->assertSee($poMist->po_number)
            ->assertDontSee($poCream->po_number);
    }

    public function test_quick_mitra_tak_bisa_intip_po_mitra_lain(): void
    {
        $a = $this->user(User::ROLE_DISTRIBUTOR);
        $b = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product('X');
        $po = $this->svc()->createForPartner($b, [['product_id' => $p->id, 'qty' => 1]], null, null);

        $this->actingAs($a)->getJson("/purchase-orders/{$po->id}/quick")->assertForbidden();
    }
}
