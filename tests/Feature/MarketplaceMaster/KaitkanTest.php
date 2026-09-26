<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class KaitkanTest extends TestCase
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

    public function test_kaitkan_bulk_menautkan_listing_ke_master(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'FM-1', 'name' => 'Hana', 'name_key' => 'hana']);
        $l1 = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'A', 'item_id' => 'P1', 'master_id' => null]);
        $l2 = MarketplaceListing::create(['channel' => 'shopee', 'seller_sku' => 'B', 'item_id' => 'P2', 'master_id' => null]);

        $this->actingAs($this->admin())
            ->post(route('marketplace-stock.kaitkan', $m), ['listing_ids' => [$l1->id, $l2->id]])
            ->assertRedirect()->assertSessionHas('status');

        $this->assertSame($m->id, $l1->refresh()->master_id);
        $this->assertSame($m->id, $l2->refresh()->master_id);
    }

    public function test_lepas_hanya_melepas_listing_milik_master_itu(): void
    {
        $m1 = MarketplaceMaster::create(['master_sku' => 'M1', 'name' => 'M1', 'name_key' => 'm1']);
        $m2 = MarketplaceMaster::create(['master_sku' => 'M2', 'name' => 'M2', 'name_key' => 'm2']);
        $own = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'A', 'item_id' => 'P1', 'master_id' => $m1->id]);
        $other = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'B', 'item_id' => 'P2', 'master_id' => $m2->id]);

        // coba lepas dua-duanya lewat m1 — hanya $own yang boleh lepas
        $this->actingAs($this->admin())
            ->post(route('marketplace-stock.lepas', $m1), ['listing_ids' => [$own->id, $other->id]])
            ->assertRedirect();

        $this->assertNull($own->refresh()->master_id);          // dilepas
        $this->assertSame($m2->id, $other->refresh()->master_id); // TAK terlepas (milik m2)
    }

    public function test_kaitkan_validasi_listing_ids_wajib_array(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'X', 'name' => 'X', 'name_key' => 'x']);
        $this->actingAs($this->admin())
            ->post(route('marketplace-stock.kaitkan', $m), [])
            ->assertSessionHasErrors('listing_ids');
    }

    public function test_hq_tak_tersentuh(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'X', 'name' => 'X', 'name_key' => 'x', 'base_stock' => 5, 'seeded_at' => now()]);
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'A', 'item_id' => 'P1', 'master_id' => null]);
        $before = DB::table('stock_movements')->count();
        $this->actingAs($this->admin())->post(route('marketplace-stock.kaitkan', $m), ['listing_ids' => [$l->id]]);
        $this->assertSame($before, DB::table('stock_movements')->count());
    }

    public function test_akses_ditolak_non_izin(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'X', 'name' => 'X', 'name_key' => 'x']);
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'A', 'item_id' => 'P1']);
        $this->actingAs($this->reseller())
            ->post(route('marketplace-stock.kaitkan', $m), ['listing_ids' => [$l->id]])
            ->assertForbidden();
    }
}
