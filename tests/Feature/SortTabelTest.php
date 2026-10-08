<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\MarketplaceMaster;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Sort per kolom ala tabel lain (header = tautan, ↑/↓, klik lagi balik arah) di Produk Master HQ, Pemantauan Stok
 * (dua tabel, parameter terpisah) dan katalog Produk Master E-commerce. Urutan dicek lewat penanda sel baris.
 */
class SortTabelTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, ?string $u = null, ?string $toko = null): User
    {
        $u ??= $role;

        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test", 'company_name' => $toko,
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function produk(string $nama, string $sku, int $stok, int $retail, int $hpp): Product
    {
        return Product::create(['name' => $nama, 'sku' => $sku, 'status' => 'active', 'hq_stock' => $stok,
            'price_distributor' => 5000, 'price_reseller' => 6000, 'price_retail' => $retail, 'cogs' => $hpp]);
    }

    public function test_produk_master_bisa_diurutkan_per_kolom_dan_sort_hpp_hanya_dengan_izin(): void
    {
        $this->produk('Bravo', 'B-02', 5, 30000, 9000);
        $this->produk('Alpha', 'C-03', 50, 20000, 1000);
        $this->produk('Charlie', 'A-01', 20, 10000, 5000);
        $admin = $this->user(User::ROLE_ADMIN);
        $urut = fn (array $p = [], ?User $u = null) => $this->actingAs($u ?? $admin)->get(route('products.index', $p))->assertOk();
        $sku = fn (string ...$s) => array_map(fn ($x) => ">{$x}</td>", $s); // sel SKU tiap baris

        $urut()->assertSeeInOrder($sku('C-03', 'B-02', 'A-01'), false);                        // bawaan: Nama A→Z
        $urut(['sort' => 'stok'])->assertSeeInOrder($sku('B-02', 'A-01', 'C-03'), false)->assertSee('Stok Pusat ↑');
        $urut(['sort' => 'stok', 'dir' => 'desc'])->assertSeeInOrder($sku('C-03', 'A-01', 'B-02'), false)->assertSee('Stok Pusat ↓');
        $urut(['sort' => 'sku'])->assertSeeInOrder($sku('A-01', 'B-02', 'C-03'), false);
        $urut(['sort' => 'retail', 'dir' => 'desc'])->assertSeeInOrder($sku('B-02', 'C-03', 'A-01'), false);
        // Tanpa izin Lihat HPP sort HPP diabaikan (urutan pun membocorkan HPP) → kembali Nama A→Z.
        $urut(['sort' => 'hpp'])->assertSeeInOrder($sku('C-03', 'B-02', 'A-01'), false);
        $urut(['sort' => 'hpp'], $this->user(User::ROLE_SUPER_ADMIN))->assertSeeInOrder($sku('C-03', 'A-01', 'B-02'), false);
        $urut(['sort' => ['stok']])->assertSeeInOrder($sku('C-03', 'B-02', 'A-01'), false);  // nilai ngawur → bawaan
        // Filter ikut terbawa di tautan sort, sort ikut terbawa saat filter dikirim.
        $urut(['q' => 'a', 'sort' => 'stok'])->assertSee('name="sort" value="stok"', false)
            ->assertSee(e(route('products.index', ['q' => 'a', 'sort' => 'stok', 'dir' => 'desc'])), false);
    }

    public function test_pemantauan_stok_dua_tabel_diurutkan_terpisah(): void
    {
        $serum = $this->produk('Serum', 'SR-1', 40, 1, 1);
        $sabun = $this->produk('Sabun', 'SB-1', 5, 1, 1);
        $toner = $this->produk('Toner', 'TN-1', 90, 1, 1);
        Inventory::create(['user_id' => $this->user(User::ROLE_RESELLER, 'citra', 'Toko Citra')->id, 'product_id' => $serum->id, 'quantity' => 5, 'minimum_stock' => 2]);
        Inventory::create(['user_id' => $this->user(User::ROLE_RESELLER, 'alfa', 'Toko Alfa')->id, 'product_id' => $sabun->id, 'quantity' => 30, 'minimum_stock' => 9]);
        Inventory::create(['user_id' => $this->user(User::ROLE_RESELLER, 'budi', 'Toko Budi')->id, 'product_id' => $toner->id, 'quantity' => 12, 'minimum_stock' => 1]);
        $admin = $this->user(User::ROLE_ADMIN);
        $urut = fn (array $p = []) => $this->actingAs($admin)->get(route('inventory.index', $p))->assertOk();
        $hq = fn (string ...$s) => array_map(fn ($x) => "<td class=\"text-stone-500\">{$x}</td>", $s); // sel SKU tabel Stok Pusat
        $mitra = fn (string ...$s) => array_map(fn ($x) => ">{$x}</td>", $s);                          // sel Mitra tabel stok mitra

        $urut()->assertSeeInOrder($hq('SB-1', 'SR-1', 'TN-1'), false)                              // bawaan: Produk A→Z
            ->assertSeeInOrder($mitra('Toko Citra', 'Toko Alfa', 'Toko Budi'), false);              // bawaan: terakhir berubah
        $urut(['hq_sort' => 'stok', 'hq_dir' => 'desc'])->assertSeeInOrder($hq('TN-1', 'SR-1', 'SB-1'), false)
            ->assertSeeInOrder($mitra('Toko Citra', 'Toko Alfa', 'Toko Budi'), false);              // tabel lain tak ikut
        $urut(['sort' => 'qty', 'dir' => 'desc'])->assertSeeInOrder($mitra('Toko Alfa', 'Toko Budi', 'Toko Citra'), false)
            ->assertSeeInOrder($hq('SB-1', 'SR-1', 'TN-1'), false);
        $urut(['sort' => 'mitra'])->assertSeeInOrder($mitra('Toko Alfa', 'Toko Budi', 'Toko Citra'), false);
        $urut(['sort' => 'produk', 'dir' => 'desc'])->assertSeeInOrder($mitra('Toko Budi', 'Toko Citra', 'Toko Alfa'), false);
        $urut(['sort' => 'min'])->assertSeeInOrder($mitra('Toko Budi', 'Toko Citra', 'Toko Alfa'), false);
    }

    public function test_katalog_ecommerce_diurutkan_harga_varian_stok_bundle_dan_kosong_di_bawah(): void
    {
        $toner = MarketplaceMaster::create(['master_sku' => 'M-1', 'name' => 'Toner B', 'base_price' => 10000, 'base_stock' => 50]);
        $sabun = MarketplaceMaster::create(['master_sku' => 'M-2', 'name' => 'Sabun C']);
        MarketplaceMaster::create(['parent_id' => $sabun->id, 'variant_name' => '1 Pcs', 'master_sku' => 'M-2A', 'name' => 'x', 'base_price' => 25000, 'base_stock' => 7]);
        MarketplaceMaster::create(['parent_id' => $sabun->id, 'variant_name' => '3 Pcs', 'master_sku' => 'M-2B', 'name' => 'y', 'base_price' => 20000, 'base_stock' => 8]);
        MarketplaceMaster::create(['master_sku' => 'M-3', 'name' => 'Serum A', 'base_price' => 30000]); // stok belum diisi
        $paket = MarketplaceMaster::create(['master_sku' => 'M-4', 'name' => 'Paket D', 'base_price' => 60000, 'is_bundle' => true]);
        $paket->bundleItems()->create(['component_id' => $toner->id, 'qty' => 2]);              // stok hitungan 50 ÷ 2 = 25
        $admin = $this->user(User::ROLE_ADMIN);
        $urut = fn (array $p = []) => $this->actingAs($admin)->get(route('marketplace-stock.index', $p))->assertOk();
        $nama = fn (string ...$s) => array_map(fn ($x) => "<div class=\"font-medium text-stone-800\">{$x}</div>", $s);

        $urut()->assertSeeInOrder($nama('Paket D', 'Sabun C', 'Serum A', 'Toner B'), false);      // bawaan: Nama A→Z
        $urut(['sort' => 'harga'])->assertSeeInOrder($nama('Toner B', 'Sabun C', 'Serum A', 'Paket D'), false)->assertSee('Harga ↑');
        $urut(['sort' => 'harga', 'dir' => 'desc'])->assertSeeInOrder($nama('Paket D', 'Serum A', 'Sabun C', 'Toner B'), false);
        $urut(['sort' => 'stok'])->assertSeeInOrder($nama('Sabun C', 'Paket D', 'Toner B', 'Serum A'), false);
        $urut(['sort' => 'stok', 'dir' => 'desc'])->assertSeeInOrder($nama('Toner B', 'Paket D', 'Sabun C', 'Serum A'), false); // kosong tetap di bawah
        $urut(['sort' => 'sku'])->assertSeeInOrder($nama('Toner B', 'Sabun C', 'Serum A', 'Paket D'), false);
        // Pindah tab mempertahankan urutan.
        $urut(['sort' => 'harga', 'dir' => 'desc'])->assertSee(e(route('marketplace-stock.index', ['tab' => 'bundle', 'sort' => 'harga', 'dir' => 'desc'])), false);
    }
}
