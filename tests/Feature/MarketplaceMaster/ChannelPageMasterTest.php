<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\MarketplaceMasterChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChannelPageMasterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'a', 'fullname' => 'A', 'username' => 'a'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE]);
    }

    public function test_set_override_stok_hanya_channel_itu(): void
    {
        Http::fake(['*' => Http::response(['code' => 0, 'data' => []])]);
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'base_stock' => 20]);
        MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'A', 'master_id' => $m->id, 'item_id' => 'P']);

        $this->actingAs($this->admin())->post(route('marketplace-stock.channel.stok', ['channel' => 'tiktok', 'master' => $m]), ['quantity' => 5])->assertRedirect();
        $this->assertSame(5, MarketplaceMasterChannel::where('master_id', $m->id)->where('channel', 'tiktok')->value('stock'));
    }

    public function test_ikut_master_hapus_override(): void
    {
        Http::fake(['*' => Http::response(['code' => 0, 'data' => []])]);
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'base_stock' => 20]);
        MarketplaceMasterChannel::create(['master_id' => $m->id, 'channel' => 'tiktok', 'stock' => 5]);

        $this->actingAs($this->admin())->post(route('marketplace-stock.ikut-master', ['channel' => 'tiktok', 'master' => $m]), ['field' => 'stock'])->assertRedirect();
        $this->assertSame(0, MarketplaceMasterChannel::where('master_id', $m->id)->count());
    }

    public function test_channel_invalid_404(): void
    {
        $this->actingAs($this->admin())->get('/marketplace-stock/lazada')->assertNotFound();
    }

    public function test_channel_page_render(): void
    {
        Http::fake(['*' => Http::response(['code' => 0, 'data' => []])]);
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'base_stock' => 20]);
        MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'A', 'master_id' => $m->id, 'item_id' => 'P']);

        $this->actingAs($this->admin())->get('/marketplace-stock/tiktok')
            ->assertOk()
            ->assertSee($m->name);
    }
}
