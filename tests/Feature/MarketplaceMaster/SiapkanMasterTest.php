<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiapkanMasterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'a', 'fullname' => 'A', 'username' => 'a'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE]);
    }

    public function test_siapkan_hapus_master_orphan(): void
    {
        // Master tanpa listing (orphan) harus terhapus; master dgn listing tetap.
        $orphan = MarketplaceMaster::create(['master_sku' => 'OLD', 'name' => 'Old', 'name_key' => 'old']);
        $keep = MarketplaceMaster::create(['master_sku' => 'FM-1', 'name' => 'Face Mist', 'name_key' => 'face mist']);
        MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'FM-1', 'item_id' => 'P1', 'master_id' => $keep->id]);
        Http::fake(['*' => Http::response(['code' => 0, 'data' => []], 200)]); // resolve: tak ada koneksi → 0, aman

        $this->actingAs($this->admin())->post(route('marketplace-stock.siapkan'))->assertRedirect()->assertSessionHas('status');

        $this->assertNull(MarketplaceMaster::find($orphan->id));
        $this->assertNotNull(MarketplaceMaster::find($keep->id));
    }

    public function test_siapkan_tidak_hapus_orphan_yang_punya_data(): void
    {
        // Orphan (tanpa listing) tapi SUDAH dikonfigurasi (harga di-set manual) — jangan dihapus diam-diam.
        $orphanBerdata = MarketplaceMaster::create(['master_sku' => 'CFG-1', 'name' => 'Sudah Diisi', 'name_key' => 'sudah diisi', 'base_price' => 15000]);
        Http::fake(['*' => Http::response(['code' => 0, 'data' => []], 200)]);

        $this->actingAs($this->admin())->post(route('marketplace-stock.siapkan'))->assertRedirect()->assertSessionHas('status');

        $this->assertNotNull(MarketplaceMaster::find($orphanBerdata->id));
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

    public function test_mitra_ditolak(): void
    {
        $r = User::create(['name' => 'r', 'fullname' => 'R', 'username' => 'r'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE]);
        $this->actingAs($r)->post(route('marketplace-stock.siapkan'))->assertForbidden();
    }
}
