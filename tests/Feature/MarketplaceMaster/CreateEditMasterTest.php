<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CreateEditMasterTest extends TestCase
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

    public function test_form_tambah_render(): void
    {
        $this->actingAs($this->admin())->get(route('marketplace-stock.create'))
            ->assertOk()->assertSee('Master SKU')->assertSee('Nama Produk');
    }

    public function test_store_buat_master_manual(): void
    {
        $this->actingAs($this->admin())->post(route('marketplace-stock.store'), [
            'name' => 'Hana Glow Face Mist 30ml', 'master_sku' => 'FM-1',
            'price' => '35000', 'stock' => '12', 'is_bundle' => '0',
        ])->assertRedirect(route('marketplace-stock.index'))->assertSessionHas('status');

        $m = MarketplaceMaster::where('master_sku', 'FM-1')->first();
        $this->assertNotNull($m);
        $this->assertSame('Hana Glow Face Mist 30ml', $m->name);
        $this->assertSame('hana glow face mist 30ml', $m->name_key);
        $this->assertSame(12, $m->base_stock);
        $this->assertSame('35000.00', (string) $m->base_price);
        $this->assertFalse($m->is_bundle);
        $this->assertNotNull($m->seeded_at); // di-set oleh setMasterStock (guard applyOrderDelta)
    }

    public function test_store_tandai_bundle_dan_stok_harga_boleh_kosong(): void
    {
        $this->actingAs($this->admin())->post(route('marketplace-stock.store'), [
            'name' => 'Paket Bundling Soap', 'master_sku' => 'SOAP3', 'is_bundle' => '1',
        ])->assertRedirect();
        $m = MarketplaceMaster::where('master_sku', 'SOAP3')->first();
        $this->assertTrue($m->is_bundle);
        $this->assertNull($m->base_stock);
        $this->assertNull($m->base_price);
    }

    public function test_store_dengan_foto(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin())->post(route('marketplace-stock.store'), [
            'name' => 'Body Wash', 'master_sku' => 'BW-1', 'foto' => UploadedFile::fake()->image('bw.jpg'),
        ])->assertRedirect();
        $m = MarketplaceMaster::where('master_sku', 'BW-1')->first();
        $this->assertNotNull($m->imageUrl());
    }

    public function test_store_validasi_wajib(): void
    {
        $this->actingAs($this->admin())->post(route('marketplace-stock.store'), ['name' => ''])
            ->assertSessionHasErrors(['name', 'master_sku']);
        $this->assertSame(0, MarketplaceMaster::count());
    }

    public function test_update_master(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'X-1', 'name' => 'X', 'name_key' => 'x']);
        $this->actingAs($this->admin())->put(route('marketplace-stock.update', $m), [
            'name' => 'X Baru', 'master_sku' => 'X-2', 'price' => '5000', 'stock' => '3', 'is_bundle' => '1',
        ])->assertRedirect(route('marketplace-stock.index'));
        $m->refresh();
        $this->assertSame('X Baru', $m->name);
        $this->assertSame('x baru', $m->name_key);
        $this->assertSame('X-2', $m->master_sku);
        $this->assertTrue($m->is_bundle);
        $this->assertSame(3, $m->base_stock);
    }

    public function test_duplicate_master_mulai_bersih(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A-1', 'name' => 'A', 'name_key' => 'a', 'is_bundle' => true, 'base_price' => 9000, 'base_stock' => 50]);
        MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'A-1', 'item_id' => 'P1', 'master_id' => $m->id]);

        $this->actingAs($this->admin())->post(route('marketplace-stock.duplikat', $m))->assertRedirect();

        $copy = MarketplaceMaster::where('master_sku', 'A-1-COPY')->first();
        $this->assertNotNull($copy);
        $this->assertSame('A (copy)', $copy->name);
        $this->assertTrue($copy->is_bundle);
        $this->assertSame('9000.00', (string) $copy->base_price);
        $this->assertNull($copy->base_stock);            // stok mulai bersih
        $this->assertSame(0, $copy->listings()->count()); // tanpa listing
        $this->assertSame(1, $m->listings()->count());    // asli tak terganggu
    }

    public function test_hq_tak_tersentuh_saat_buat_master(): void
    {
        $before = DB::table('stock_movements')->count();
        $this->actingAs($this->admin())->post(route('marketplace-stock.store'), ['name' => 'Z', 'master_sku' => 'Z-1', 'stock' => '10']);
        $this->assertSame($before, DB::table('stock_movements')->count());
    }

    public function test_akses_ditolak_non_izin(): void
    {
        $r = $this->reseller();
        $this->actingAs($r)->get(route('marketplace-stock.create'))->assertForbidden();
        $this->actingAs($r)->post(route('marketplace-stock.store'), ['name' => 'x', 'master_sku' => 'x'])->assertForbidden();
    }
}
