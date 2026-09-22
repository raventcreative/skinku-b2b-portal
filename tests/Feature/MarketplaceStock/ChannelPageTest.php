<?php

namespace Tests\Feature\MarketplaceStock;

use App\Models\MarketplaceChannelOverride;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceStock;
use App\Models\Product;
use App\Models\TiktokConnection;
use App\Models\TiktokSkuMap;
use App\Models\User;
use App\Services\MarketplaceStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Task 4 (Fase 1.5): sidebar 3 item (Stok Master/TikTok/Shopee) + halaman
 * per-channel dgn override + "Ikut Master". Logika hitung/simpan override
 * sendiri sudah dites tuntas di Task 1-3 (ChannelOverrideModelTest/dst); di
 * sini cukup pastikan controller+rute+view merangkainya dengan benar.
 */
class ChannelPageTest extends TestCase
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

    public function test_halaman_channel_render_dan_set_override_hanya_channel_itu(): void
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
        app(MarketplaceStockService::class)->setPool($p, 20);
        Http::fake(['*' => Http::response(['code' => 0, 'data' => []])]);

        $this->actingAs($this->admin())->get('/marketplace-stock/tiktok')->assertOk()->assertSee('Face Mist');
        $this->actingAs($this->admin())->post("/marketplace-stock/tiktok/override/{$p->id}", ['quantity' => 5])->assertRedirect();

        $this->assertSame(5, MarketplaceChannelOverride::where('product_id', $p->id)->where('channel', 'tiktok')->value('quantity'));
        $this->assertSame(20, MarketplaceStock::where('product_id', $p->id)->value('quantity')); // master tak berubah
        $this->assertSame(0, MarketplaceChannelOverride::where('product_id', $p->id)->where('channel', 'shopee')->count()); // hanya channel itu
    }

    public function test_ikut_master_hapus_override(): void
    {
        $p = $this->product('FM-1', 'Face Mist');
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        app(MarketplaceStockService::class)->setChannelOverride($p, 'tiktok', 5);
        Http::fake();

        $this->actingAs($this->admin())->post("/marketplace-stock/tiktok/ikut-master/{$p->id}")->assertRedirect();

        $this->assertSame(0, MarketplaceChannelOverride::where('product_id', $p->id)->count());
    }

    public function test_channel_invalid_404(): void
    {
        $this->actingAs($this->admin())->get('/marketplace-stock/lazada')->assertNotFound();
    }

    public function test_master_menampilkan_penanda_override_per_channel(): void
    {
        $p = $this->product('FM-1', 'Face Mist');
        TiktokSkuMap::create(['tiktok_sku' => 'FM-1', 'product_id' => $p->id, 'qty' => 1]);
        app(MarketplaceStockService::class)->setChannelOverride($p, 'tiktok', 9);

        $this->actingAs($this->admin())->get('/marketplace-stock')
            ->assertOk()
            ->assertSee('Face Mist')
            ->assertSee('Override: 9');
    }

    public function test_mitra_ditolak_pada_override(): void
    {
        $p = $this->product('FM-1', 'Face Mist');
        $reseller = User::create([
            'name' => 'r', 'fullname' => 'R', 'username' => 'r'.uniqid(),
            'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'),
            'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE,
        ]);

        $this->actingAs($reseller)
            ->post("/marketplace-stock/tiktok/override/{$p->id}", ['quantity' => 5])
            ->assertForbidden();
    }
}
