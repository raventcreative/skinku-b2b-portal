<?php

namespace Tests\Feature\MarketplaceStock;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceStock;
use App\Models\Product;
use App\Models\RolePermission;
use App\Models\TiktokConnection;
use App\Models\TiktokSkuMap;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Task 10: halaman "Stok Marketplace" (tabel produk terpetakan + aksi) + menu
 * sidebar. Ini tes UI/HTTP — logika hitung/push sendiri sudah dites tuntas di
 * Task 3-9 (MarketplaceModelTest/PushStockTest/dst); di sini cukup pastikan
 * controller+view merangkai service yg sudah ada dengan benar.
 */
class MarketplaceUiTest extends TestCase
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

    private function product(string $sku, string $name): Product
    {
        return Product::create([
            'name' => $name, 'sku' => $sku, 'status' => 'active',
            'price_distributor' => 1, 'price_reseller' => 1,
        ]);
    }

    // ---- Render: produk yg punya peta SKU tampil di tabel ----

    public function test_halaman_menampilkan_produk_yang_terpetakan(): void
    {
        $p = $this->product('FM-1', 'Face Mist');
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        MarketplaceListing::create([
            'channel' => 'tiktok', 'seller_sku' => 'FM-1', 'item_id' => 'PID1',
            'variation_id' => 'SID1', 'warehouse_id' => 'WH1', 'resolved_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->get('/marketplace-stock')
            ->assertOk()
            ->assertSee('Face Mist')
            ->assertSee('FM-1')
            ->assertSee('belum dipetakan') // kolom Shopee: produk ini cuma dipetakan di TikTok
            ->assertSee('Stok Marketplace'); // menu sidebar Integrasi + judul halaman
    }

    public function test_halaman_menampilkan_seksi_listing_belum_terpetakan(): void
    {
        MarketplaceListing::create([
            'channel' => 'shopee', 'seller_sku' => 'ZZZ', 'item_id' => 'IT1',
            'variation_id' => '0', 'last_status' => 'unmapped', 'resolved_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->get('/marketplace-stock')
            ->assertOk()
            ->assertSee('Listing belum terpetakan')
            ->assertSee('ZZZ');
    }

    // ---- setStock: setPool lalu push langsung, tanpa HTTP nyata ----

    public function test_set_stock_menyetel_pool_lalu_menyinkron(): void
    {
        $p = $this->product('FM-1', 'Face Mist');
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        MarketplaceListing::create([
            'channel' => 'tiktok', 'seller_sku' => 'FM-1', 'item_id' => 'PID1',
            'variation_id' => 'SID1', 'warehouse_id' => 'WH1', 'resolved_at' => now(),
        ]);
        TiktokConnection::create([
            'shop_id' => 's', 'shop_cipher' => 'c', 'access_token' => 't', 'refresh_token' => 'r',
            'access_expires_at' => now()->addDay(),
        ]);
        Http::fake(['*' => Http::response(['code' => 0, 'data' => []])]);

        $this->actingAs($this->admin())
            ->post("/marketplace-stock/set/{$p->id}", ['quantity' => 25])
            ->assertRedirect();

        $this->assertSame(25, MarketplaceStock::where('product_id', $p->id)->value('quantity'));
        // pushProduct() dipanggil langsung setelah setPool -> listing ikut tercatat
        $this->assertSame(25, MarketplaceListing::where('seller_sku', 'FM-1')->value('last_pushed_qty'));
        Http::assertSentCount(1);
    }

    public function test_set_stock_menolak_quantity_negatif(): void
    {
        $p = $this->product('FM-1', 'Face Mist');
        Http::fake();

        $this->actingAs($this->admin())
            ->post("/marketplace-stock/set/{$p->id}", ['quantity' => -1])
            ->assertSessionHasErrors('quantity');

        $this->assertSame(0, MarketplaceStock::where('product_id', $p->id)->count());
        Http::assertNothingSent();
    }

    // ---- push-all / resolve / seed: rute ada, terhubung ke service, redirect dgn status ----

    public function test_push_all_resolve_dan_seed_meredirect_dengan_status(): void
    {
        Http::fake(['*' => Http::response(['code' => 0, 'data' => []])]);
        $admin = $this->admin();

        $this->actingAs($admin)->post('/marketplace-stock/push-all')
            ->assertRedirect()->assertSessionHas('status');

        $this->actingAs($admin)->post('/marketplace-stock/resolve')
            ->assertRedirect()->assertSessionHas('status');

        $this->actingAs($admin)->post('/marketplace-stock/seed-tiktok')
            ->assertRedirect()->assertSessionHas('status');
    }

    // ---- resolve/seed: error API (mis. scope Product belum aktif) -> pesan rapi, bukan 500 ----

    public function test_resolve_menampilkan_error_bukan_500_saat_api_gagal(): void
    {
        TiktokConnection::create([
            'shop_id' => 's', 'shop_cipher' => 'c', 'access_token' => 't', 'refresh_token' => 'r',
            'access_expires_at' => now()->addDay(),
        ]);
        // TikTok membalas galat (mis. scope Product belum aktif) -> client throw -> controller wajib tangkap
        Http::fake(['*' => Http::response(['code' => 36004, 'message' => 'no permission'], 200)]);

        $this->actingAs($this->admin())->post('/marketplace-stock/resolve')
            ->assertRedirect()->assertSessionHas('error');
    }

    public function test_seed_menampilkan_error_bukan_500_saat_api_gagal(): void
    {
        TiktokConnection::create([
            'shop_id' => 's', 'shop_cipher' => 'c', 'access_token' => 't', 'refresh_token' => 'r',
            'access_expires_at' => now()->addDay(),
        ]);
        Http::fake(['*' => Http::response(['code' => 36004, 'message' => 'no permission'], 200)]);

        $this->actingAs($this->admin())->post('/marketplace-stock/seed-tiktok')
            ->assertRedirect()->assertSessionHas('error');
    }

    // ---- push per-baris ----

    public function test_push_satu_produk_meredirect_dengan_status(): void
    {
        $p = $this->product('FM-1', 'Face Mist');
        Http::fake();

        $this->actingAs($this->admin())
            ->post("/marketplace-stock/push/{$p->id}")
            ->assertRedirect()->assertSessionHas('status');
    }

    // ---- menu sidebar: hanya tampil untuk role yg punya izin ----

    public function test_menu_sidebar_tersembunyi_untuk_role_tanpa_izin(): void
    {
        $reseller = User::create([
            'name' => 'r', 'fullname' => 'R', 'username' => 'r'.uniqid(),
            'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'),
            'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE,
        ]);

        $this->actingAs($reseller)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Stok Marketplace');

        $this->actingAs($this->admin())->get('/dashboard')
            ->assertOk()
            ->assertSee('Stok Marketplace');
    }

    /**
     * manage_marketplace_stock adalah izin independen (bisa di-toggle sendiri di
     * matriks hak akses) — jadi role yg CUMA punya izin ini (tanpa manage_tiktok/
     * manage_shopee/manage_ecommerce_chat) harus tetap lihat menunya. Grup
     * accordion "Integrasi" pembungkusnya juga harus ikut terbuka untuk role ini,
     * bukan cuma item "Stok Marketplace"-nya sendiri.
     */
    public function test_menu_sidebar_tampil_untuk_role_yang_hanya_punya_izin_stok_marketplace(): void
    {
        $user = User::create([
            'name' => 'ms', 'fullname' => 'Stok Only', 'username' => 'stokonly'.uniqid(),
            'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'),
            'role' => 'marketplace_stock_only', 'status' => User::STATUS_ACTIVE,
        ]);
        RolePermission::create(['role' => 'marketplace_stock_only', 'permission_key' => 'manage_marketplace_stock', 'allowed' => true]);
        Permissions::flushCache();

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('Stok Marketplace');
    }

    // ---- izin: mitra tanpa manage_marketplace_stock tak bisa akses aksi ----

    public function test_role_tanpa_izin_ditolak_pada_aksi(): void
    {
        $p = $this->product('FM-1', 'Face Mist');
        $reseller = User::create([
            'name' => 'r', 'fullname' => 'R', 'username' => 'r'.uniqid(),
            'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'),
            'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE,
        ]);

        $this->actingAs($reseller)
            ->post("/marketplace-stock/set/{$p->id}", ['quantity' => 5])
            ->assertForbidden();
    }
}
