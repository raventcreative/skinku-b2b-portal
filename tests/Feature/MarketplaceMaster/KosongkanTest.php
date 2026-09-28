<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class KosongkanTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'a', 'fullname' => 'A', 'username' => 'a'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE]);
    }

    private function reseller(): User
    {
        return User::create(['name' => 'r', 'fullname' => 'R', 'username' => 'r'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE]);
    }

    public function test_kosongkan_menghapus_semua_master_dan_null_kan_master_id_listing(): void
    {
        $m1 = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'name_key' => 'a']);
        $m2 = MarketplaceMaster::create(['master_sku' => 'B', 'name' => 'B', 'name_key' => 'b']);
        $l1 = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'A', 'item_id' => 'P1', 'master_id' => $m1->id]);
        $l2 = MarketplaceListing::create(['channel' => 'shopee', 'seller_sku' => 'B', 'item_id' => 'P2', 'master_id' => $m2->id]);

        $this->actingAs($this->admin())
            ->post(route('marketplace-stock.kosongkan'))
            ->assertRedirect()->assertSessionHas('status');

        $this->assertSame(0, MarketplaceMaster::count());
        $this->assertNull($l1->refresh()->master_id); // FK nullOnDelete
        $this->assertNull($l2->refresh()->master_id); // FK nullOnDelete
    }

    public function test_hq_tak_tersentuh(): void
    {
        MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'name_key' => 'a']);
        $before = DB::table('stock_movements')->count();

        $this->actingAs($this->admin())->post(route('marketplace-stock.kosongkan'));

        $this->assertSame($before, DB::table('stock_movements')->count());
    }

    public function test_akses_ditolak_non_izin(): void
    {
        MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'name_key' => 'a']);

        $this->actingAs($this->reseller())
            ->post(route('marketplace-stock.kosongkan'))
            ->assertForbidden();
    }
}
