<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CatalogPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'a', 'fullname' => 'A', 'username' => 'a'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE]);
    }

    public function test_empty_state_saat_belum_ada_master(): void
    {
        $this->actingAs($this->admin())->get(route('marketplace-stock.index'))
            ->assertOk()
            ->assertSee('Belum ada produk master')
            ->assertSee('Tambah Produk Baru');
    }

    public function test_kolom_desty_dan_baris_master(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'FM-1', 'name' => 'Hana Glow', 'name_key' => 'hana glow', 'base_price' => 35000, 'base_stock' => 9]);
        MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'FM-1', 'item_id' => 'P1', 'master_id' => $m->id]);
        MarketplaceListing::create(['channel' => 'shopee', 'seller_sku' => 'FM-1S', 'item_id' => 'P2', 'master_id' => $m->id]);

        $res = $this->actingAs($this->admin())->get(route('marketplace-stock.index'))->assertOk();
        foreach (['Informasi Produk', 'Master SKU', 'Harga', 'Stok', 'Produk Terkait', 'Toko Terkait', 'Atur'] as $col) {
            $res->assertSee($col);
        }
        $res->assertSee('Hana Glow')->assertSee('FM-1');
        $res->assertSee('2 Produk'); // 2 listing tertaut
        $res->assertSee('2 Toko');   // 2 channel unik
    }

    public function test_menu_atur_berisi_aksi_desty(): void
    {
        MarketplaceMaster::create(['master_sku' => 'X-1', 'name' => 'X', 'name_key' => 'x']);
        $res = $this->actingAs($this->admin())->get(route('marketplace-stock.index'))->assertOk();
        foreach (['Ubah', 'Duplikat Produk', 'Tambah ke Marketplace', 'Jadikan Bundle', 'Hapus'] as $act) {
            $res->assertSee($act);
        }
    }

    public function test_tab_bundle_hanya_bundle(): void
    {
        MarketplaceMaster::create(['master_sku' => 'S-1', 'name' => 'Satuan', 'name_key' => 'satuan', 'is_bundle' => false]);
        MarketplaceMaster::create(['master_sku' => 'B-1', 'name' => 'Paket', 'name_key' => 'paket', 'is_bundle' => true]);

        $this->actingAs($this->admin())->get(route('marketplace-stock.index', ['tab' => 'bundle']))
            ->assertOk()->assertSee('Paket')->assertDontSee('>Satuan<', false);
    }

    public function test_picker_tambah_ke_marketplace_berisi_listing_belum_tertaut(): void
    {
        MarketplaceMaster::create(['master_sku' => 'X-1', 'name' => 'X', 'name_key' => 'x']);
        MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'NEW-SKU', 'item_id' => 'P9', 'master_id' => null, 'title' => 'Produk Baru']);

        $this->actingAs($this->admin())->get(route('marketplace-stock.index'))
            ->assertOk()->assertSee('NEW-SKU');
    }

    public function test_akses_ditolak_non_izin(): void
    {
        $r = User::create(['name' => 'r', 'fullname' => 'R', 'username' => 'r'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE]);
        $this->actingAs($r)->get(route('marketplace-stock.index'))->assertForbidden();
    }
}
