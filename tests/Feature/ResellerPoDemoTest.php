<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ResellerPoDemoTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $role): User
    {
        $u = 'u'.(++$this->seq);

        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'company_name' => 'CV '.$u, 'role' => $role,
            'status' => User::STATUS_ACTIVE,
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

    public function test_reseller_lihat_form_po_sebagai_demo_dan_menu_stok_hilang(): void
    {
        $reseller = $this->user(User::ROLE_RESELLER_BRONZE);

        $this->actingAs($reseller)->get(route('purchase-orders.create'))
            ->assertOk()
            ->assertSee('Mode lihat saja')       // banner demo muncul
            ->assertDontSee('Stok Saya');         // menu stok disembunyikan buat reseller
    }

    public function test_reseller_tak_bisa_submit_po(): void
    {
        $reseller = $this->user(User::ROLE_RESELLER_BRONZE);
        $p = $this->product();

        $this->actingAs($reseller)->post(route('purchase-orders.store'), [
            'items' => [['product_id' => $p->id, 'qty' => 2]],
        ])->assertForbidden(); // 403 — server tetap nolak walau form di-bypass
    }

    public function test_distributor_buat_po_normal_dan_lihat_menu_stok(): void
    {
        $dist = $this->user(User::ROLE_DISTRIBUTOR);

        $this->actingAs($dist)->get(route('purchase-orders.create'))
            ->assertOk()
            ->assertDontSee('Mode lihat saja')    // bukan demo
            ->assertSee('Stok Saya');             // distributor pegang stok → menu tampil

        $p = $this->product();
        $this->actingAs($dist)->post(route('purchase-orders.store'), [
            'items' => [['product_id' => $p->id, 'qty' => 2]],
        ])->assertRedirect(); // sukses bikin PO (bukan 403)
    }
}
