<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tombol "Buat master otomatis (semua)" — jadikan master SEMUA listing yang
 * belum termaster dari data listing sendiri (tanpa API), biar admin tak perlu
 * Tautkan satu-satu & listing warisan pra-rework langsung bermaster.
 */
class MasterizeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'a', 'fullname' => 'A', 'username' => 'a'.uniqid(),
            'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'),
            'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_masterize_semua_listing_belum_termaster(): void
    {
        MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'FM-1', 'item_id' => 'P1', 'title' => 'Hana Glow Face Mist']);
        MarketplaceListing::create(['channel' => 'shopee', 'seller_sku' => 'BBC-1', 'item_id' => 'P2', 'title' => 'Day Cream']);

        $this->actingAs($this->admin())
            ->post(route('marketplace-stock.masterize-all'))
            ->assertRedirect()
            ->assertSessionHas('status');

        // Kedua listing kini termaster, master ke-bikin dari seller_sku + title.
        $this->assertSame(0, MarketplaceListing::whereNull('master_id')->count());
        $fm = MarketplaceMaster::where('master_sku', 'FM-1')->first();
        $this->assertNotNull($fm);
        $this->assertSame('Hana Glow Face Mist', $fm->name);
        $this->assertSame($fm->id, MarketplaceListing::where('seller_sku', 'FM-1')->value('master_id'));
        $this->assertNotNull(MarketplaceMaster::where('master_sku', 'BBC-1')->first());
    }

    public function test_idempoten_tak_sentuh_listing_yang_sudah_termaster(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'X-1', 'name' => 'X']);
        $mapped = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'X-1', 'item_id' => 'P1', 'master_id' => $m->id]);
        MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'Y-1', 'item_id' => 'P2', 'title' => 'Y']);

        $this->actingAs($this->admin())->post(route('marketplace-stock.masterize-all'))->assertRedirect();

        // Listing yang sudah termaster tetap ke master lamanya; total master = 2 (X-1 + Y-1).
        $this->assertSame($m->id, $mapped->refresh()->master_id);
        $this->assertSame(2, MarketplaceMaster::count());
    }

    public function test_mitra_ditolak(): void
    {
        $reseller = User::create([
            'name' => 'r', 'fullname' => 'R', 'username' => 'r'.uniqid(),
            'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'),
            'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE,
        ]);
        $this->actingAs($reseller)->post(route('marketplace-stock.masterize-all'))->assertForbidden();
    }
}
