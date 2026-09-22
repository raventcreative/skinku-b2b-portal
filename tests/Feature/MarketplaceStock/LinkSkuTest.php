<?php

namespace Tests\Feature\MarketplaceStock;

use App\Models\MarketplaceListing;
use App\Models\Product;
use App\Models\TiktokSkuMap;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Task 5 (Fase 1.5): tautkan SKU listing yang belum terpetakan LANGSUNG dari
 * halaman Stok (pilih produk master), tanpa bolak-balik ke halaman peta SKU.
 */
class LinkSkuTest extends TestCase
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

    public function test_tautkan_membuat_peta_sku_dan_hilang_dari_unmapped(): void
    {
        $p = $this->product('FM-1', 'Face Mist');
        MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'ZZZ', 'item_id' => 'PID9', 'variation_id' => 'S9', 'last_status' => 'unmapped', 'resolved_at' => now()]);

        $this->actingAs($this->admin())->post('/marketplace-stock/tiktok/tautkan', [
            'seller_sku' => 'ZZZ', 'product_id' => $p->id, 'qty' => 1,
        ])->assertRedirect();

        $this->assertSame($p->id, TiktokSkuMap::where('tiktok_sku', 'ZZZ')->value('product_id'));
        // listing tak lagi 'unmapped'
        $this->assertNotSame('unmapped', MarketplaceListing::where('seller_sku', 'ZZZ')->value('last_status'));
    }

    public function test_tautkan_channel_invalid_404(): void
    {
        $p = $this->product('FM-1', 'Face Mist');
        $this->actingAs($this->admin())->post('/marketplace-stock/lazada/tautkan', ['seller_sku' => 'X', 'product_id' => $p->id, 'qty' => 1])->assertNotFound();
    }
}
