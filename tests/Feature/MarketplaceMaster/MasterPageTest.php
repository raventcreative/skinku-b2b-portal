<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MasterPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'a', 'fullname' => 'A', 'username' => 'a'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE]);
    }

    public function test_render_dan_set_master_stok(): void
    {
        Http::fake(['*' => Http::response(['code' => 0, 'data' => []])]);
        $m = MarketplaceMaster::create(['master_sku' => 'FM-1', 'name' => 'Face Mist']);

        $this->actingAs($this->admin())->get('/marketplace-stock')->assertOk()->assertSee('Produk Master')->assertSee('Face Mist');

        $this->actingAs($this->admin())->post(route('marketplace-stock.master.stok', $m), ['quantity' => 25])->assertRedirect();
        $this->assertSame(25, $m->refresh()->base_stock);
    }

    public function test_set_master_harga(): void
    {
        Http::fake(['*' => Http::response(['code' => 0, 'data' => []])]);
        $m = MarketplaceMaster::create(['master_sku' => 'FM-1', 'name' => 'FM']);
        $this->actingAs($this->admin())->post(route('marketplace-stock.master.harga', $m), ['price' => 39000])->assertRedirect();
        $this->assertSame('39000.00', (string) $m->refresh()->base_price);
    }

    public function test_menu_sidebar_dan_akses_role(): void
    {
        $this->actingAs($this->admin())->get('/dashboard')->assertOk()->assertSee('Stok Marketplace');
        $reseller = User::create(['name' => 'r', 'fullname' => 'R', 'username' => 'r'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE]);
        $this->actingAs($reseller)->get('/marketplace-stock')->assertForbidden();
    }
}
