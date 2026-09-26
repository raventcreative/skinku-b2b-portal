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

    private function master(string $sku, string $name, bool $bundle = false): MarketplaceMaster
    {
        $m = MarketplaceMaster::create(['master_sku' => $sku, 'name' => $name, 'name_key' => MarketplaceMaster::normalizeName($name), 'is_bundle' => $bundle, 'base_stock' => 10, 'base_price' => 39000]);
        MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => $sku, 'item_id' => 'P'.$sku, 'master_id' => $m->id]);

        return $m;
    }

    public function test_katalog_render_dan_tab_default_semua(): void
    {
        $this->master('FM-1', 'Face Mist');
        $this->master('JPX-3', 'Bundling 3pcs', true);

        $this->actingAs($this->admin())->get('/marketplace-stock')->assertOk()
            ->assertSee('Face Mist')->assertSee('Bundling 3pcs')->assertSee('Produk Master');
    }

    public function test_tab_bundle_hanya_bundle(): void
    {
        $this->master('FM-1', 'Face Mist');
        $this->master('JPX-3', 'Bundling 3pcs', true);

        $this->actingAs($this->admin())->get('/marketplace-stock?tab=bundle')->assertOk()
            ->assertSee('Bundling 3pcs')->assertDontSee('Face Mist');
    }

    public function test_tab_satuan_hanya_satuan(): void
    {
        $this->master('FM-1', 'Face Mist');
        $this->master('JPX-3', 'Bundling 3pcs', true);

        $this->actingAs($this->admin())->get('/marketplace-stock?tab=satuan')->assertOk()
            ->assertSee('Face Mist')->assertDontSee('Bundling 3pcs');
    }

    public function test_reseller_ditolak(): void
    {
        $r = User::create(['name' => 'r', 'fullname' => 'R', 'username' => 'r'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE]);
        $this->actingAs($r)->get('/marketplace-stock')->assertForbidden();
    }
}
