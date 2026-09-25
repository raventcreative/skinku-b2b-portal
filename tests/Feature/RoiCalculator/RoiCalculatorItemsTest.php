<?php

namespace Tests\Feature\RoiCalculator;

use App\Models\Product;
use App\Models\RoiItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RoiCalculatorItemsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'admin', 'fullname' => 'Admin', 'username' => 'admin'.uniqid(),
            'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'),
            'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function product(string $sku = 'SB-1', float $cogs = 13755): Product
    {
        return Product::create(['name' => 'Sabun', 'sku' => $sku, 'status' => 'active', 'cogs' => $cogs]);
    }

    public function test_tambah_produk_membuat_baris_modal_ikut_cogs(): void
    {
        $p = $this->product();

        $this->actingAs($this->admin())
            ->post('/kalkulator-roi/items', ['product_id' => $p->id, 'selling_price' => 39000])
            ->assertRedirect()->assertSessionHas('status');

        $item = RoiItem::firstWhere('product_id', $p->id);
        $this->assertSame(39000, $item->selling_price);
        $this->assertNull($item->modal); // null -> compute pakai COGS
    }

    public function test_tak_boleh_produk_dobel(): void
    {
        $p = $this->product();
        RoiItem::create(['product_id' => $p->id, 'selling_price' => 1000]);

        $this->actingAs($this->admin())
            ->post('/kalkulator-roi/items', ['product_id' => $p->id, 'selling_price' => 2000])
            ->assertSessionHasErrors('product_id');

        $this->assertSame(1, RoiItem::where('product_id', $p->id)->count());
    }

    public function test_update_menyimpan_override_dan_modal(): void
    {
        $p = $this->product();
        $item = RoiItem::create(['product_id' => $p->id, 'selling_price' => 39000]);

        $this->actingAs($this->admin())
            ->post("/kalkulator-roi/items/{$item->id}", [
                'selling_price' => 42000, 'modal' => 15000, 'admin_pct' => 10,
                'packing' => '', 'proses_order' => '', 'voucher_pct' => '', 'komisi_pct' => '',
                'komisi_cap' => '', 'mall_pct' => '', 'pajak_pct' => '', 'operasional_pct' => '', 'affiliate_pct' => '',
            ])
            ->assertRedirect()->assertSessionHas('status');

        $item->refresh();
        $this->assertSame(42000, $item->selling_price);
        $this->assertSame(15000, $item->modal);
        $this->assertSame(10.0, $item->admin_pct);
        $this->assertNull($item->voucher_pct); // kosong -> warisi global
        $this->assertNull($item->packing);
    }

    public function test_update_kosongkan_override_kembali_warisi(): void
    {
        $p = $this->product();
        $item = RoiItem::create(['product_id' => $p->id, 'selling_price' => 39000, 'admin_pct' => 12, 'modal' => 20000]);

        $this->actingAs($this->admin())
            ->post("/kalkulator-roi/items/{$item->id}", [
                'selling_price' => 39000, 'modal' => '', 'admin_pct' => '',
                'packing' => '', 'proses_order' => '', 'voucher_pct' => '', 'komisi_pct' => '',
                'komisi_cap' => '', 'mall_pct' => '', 'pajak_pct' => '', 'operasional_pct' => '', 'affiliate_pct' => '',
            ])
            ->assertRedirect();

        $item->refresh();
        $this->assertNull($item->admin_pct); // di-kosongkan -> warisi lagi
        $this->assertNull($item->modal);
    }

    public function test_hapus_baris(): void
    {
        $p = $this->product();
        $item = RoiItem::create(['product_id' => $p->id, 'selling_price' => 39000]);

        $this->actingAs($this->admin())
            ->delete("/kalkulator-roi/items/{$item->id}")
            ->assertRedirect()->assertSessionHas('status');

        $this->assertSame(0, RoiItem::count());
    }

    public function test_menolak_selling_price_negatif_saat_tambah(): void
    {
        $p = $this->product();

        $this->actingAs($this->admin())
            ->post('/kalkulator-roi/items', ['product_id' => $p->id, 'selling_price' => -5])
            ->assertSessionHasErrors('selling_price');
    }

    public function test_edit_gagal_menampilkan_banner_error(): void
    {
        $p = $this->product();
        $item = RoiItem::create(['product_id' => $p->id, 'selling_price' => 39000]);

        $this->actingAs($this->admin())
            ->from('/kalkulator-roi')
            ->followingRedirects()
            ->post("/kalkulator-roi/items/{$item->id}", ['selling_price' => ''])
            ->assertOk()
            ->assertSee('Periksa input yang dimasukkan');
    }
}
