<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MasterActionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'a', 'fullname' => 'A', 'username' => 'a'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE]);
    }

    public function test_toggle_bundle(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'name_key' => 'a', 'is_bundle' => false]);
        $this->actingAs($this->admin())->post(route('marketplace-stock.master.bundle', $m))->assertRedirect();
        $this->assertTrue($m->refresh()->is_bundle);
        $this->actingAs($this->admin())->post(route('marketplace-stock.master.bundle', $m))->assertRedirect();
        $this->assertFalse($m->refresh()->is_bundle);
    }

    public function test_hapus_master_listing_jadi_unmastered(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'FM-1', 'name' => 'Face Mist', 'name_key' => 'face mist']);
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'FM-1', 'item_id' => 'P1', 'master_id' => $m->id]);

        $this->actingAs($this->admin())->delete(route('marketplace-stock.master.hapus', $m))->assertRedirect()->assertSessionHas('status');

        $this->assertNull(MarketplaceMaster::find($m->id));
        $this->assertNull($l->refresh()->master_id); // FK nullOnDelete
    }

    public function test_hapus_master_mitra_ditolak(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'FM-1', 'name' => 'Face Mist', 'name_key' => 'face mist']);
        $r = User::create(['name' => 'r', 'fullname' => 'R', 'username' => 'r'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE]);
        $this->actingAs($r)->delete(route('marketplace-stock.master.hapus', $m))->assertForbidden();
    }
}
