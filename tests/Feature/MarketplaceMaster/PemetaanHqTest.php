<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\Product;
use App\Models\TiktokSkuMap;
use App\Models\User;
use App\Services\MarketplaceMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Halaman Pemetaan ke HQ: tebakan produk gudang (SKU map / SKU sama / nama mirip, seri → tak menebak), usulan
 * "Jadikan Bundle", resep dari HQ; simpan sekali = tanda → bundle → resep (hanya bila lengkap). Stok tak berubah.
 */
class PemetaanHqTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = User::ROLE_SUPER_ADMIN): User
    {
        return User::create(['name' => 'u', 'fullname' => 'U', 'username' => 'u'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE]);
    }

    private function master(string $sku, string $name, array $over = []): MarketplaceMaster
    {
        return MarketplaceMaster::create(array_merge(['master_sku' => $sku, 'name' => $name, 'base_stock' => 10, 'seeded_at' => now()], $over));
    }

    public function test_tebakan_produk_gudang(): void
    {
        $reina = Product::create(['name' => 'REINA INTENSIVE UNDERARM BRIGHTENING - 30g', 'sku' => 'RN30', 'status' => 'active']);
        $yuki = Product::create(['name' => 'YUKI ADVANCE BRIGHTENING - BODY LOTION - 100g', 'sku' => 'YK100', 'status' => 'active']);
        $soap = Product::create(['name' => 'Body Soap - 80g', 'sku' => 'SOAP-1', 'status' => 'active']);
        Product::create(['name' => 'Body Scrub Pink 100g', 'sku' => 'SCR', 'status' => 'active']);
        $produk = Product::orderBy('name')->get();
        $svc = app(MarketplaceMasterService::class);

        $mReina = $this->master('REI-1', 'SKIN-KU REINA Krim Pencerah Ketiak 30g');
        MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'SK-REI', 'master_id' => $mReina->id]);
        TiktokSkuMap::create(['tiktok_sku' => 'SK-REI', 'product_id' => $reina->id, 'qty' => 1]);
        $this->assertSame(['id' => $reina->id, 'sumber' => 'SKU map HQ'], $svc->tebakProdukHq($mReina->load('listings'), $produk));

        $this->assertSame(['id' => $soap->id, 'sumber' => 'SKU sama'], $svc->tebakProdukHq($this->master('soap-1', 'Sabun')->load('listings'), $produk));
        $this->assertSame(['id' => $yuki->id, 'sumber' => 'nama mirip — cek'],
            $svc->tebakProdukHq($this->master('SBS-1', 'SKINKU BODY LOTION PUTIH/HILANGKAN BEKAS LUKA 100ml')->load('listings'), $produk));
        // "Body ... 100" sama-sama 2 kata dgn Body Scrub & Yuki → seri → tak menebak.
        $this->assertNull($svc->tebakProdukHq($this->master('X', 'Body Wash 100ml')->load('listings'), $produk));
    }

    public function test_halaman_dan_simpan_sekali_klik_tanpa_mengubah_stok(): void
    {
        $reina = Product::create(['name' => 'REINA 30g', 'sku' => 'RN30', 'status' => 'active']);
        $mReina = $this->master('RN30', 'SKIN-KU REINA Krim 30g', ['base_stock' => 533]);
        $bundle = $this->master('REI-3', 'BUNDLING (3 pcs) REINA', ['base_stock' => 18]);   // masih Satuan
        $tak = $this->master('BND-X', 'Paket Bundling Misteri');
        TiktokSkuMap::create(['tiktok_sku' => 'REI-3', 'product_id' => $reina->id, 'qty' => 3]);
        $admin = $this->user();

        $this->actingAs($admin)->get(route('marketplace-stock.pemetaan-hq'))->assertOk()
            ->assertSee('Tebakan: SKU sama')->assertSee('Jadikan Bundle?')->assertSee('Satuan tertandai: <b>0/3</b>', false);

        $this->actingAs($admin)->post(route('marketplace-stock.pemetaan-hq.simpan'), [
            'produk' => [$mReina->id => $reina->id],
            'jadikan_bundle' => [$bundle->id, $tak->id],
            'resep_hq' => [$bundle->id, $tak->id],
        ])->assertRedirect(route('marketplace-stock.pemetaan-hq'))
            ->assertSessionHas('status', fn ($s) => str_contains($s, '1 produk ditandai, 2 dijadikan Bundle, 1 resep'))
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'Paket Bundling Misteri'));

        $this->assertSame($reina->id, $mReina->fresh()->product_id);
        $this->assertTrue($bundle->fresh()->is_bundle);
        $this->assertSame([[$mReina->id, 3]], $bundle->bundleItems()->get()->map(fn ($x) => [$x->component_id, $x->qty])->all());
        $this->assertSame(0, $tak->bundleItems()->count());
        $this->assertSame(533, $mReina->fresh()->base_stock); // stok tak disentuh
        $this->assertSame(18, $bundle->fresh()->base_stock);
        $this->assertSame(177, app(MarketplaceMasterService::class)->effectiveStock($bundle->fresh(), 'tiktok'));
    }

    public function test_butuh_izin(): void
    {
        $this->actingAs($this->user(User::ROLE_RESELLER))->get(route('marketplace-stock.pemetaan-hq'))->assertForbidden();
        $this->actingAs($this->user(User::ROLE_RESELLER))->post(route('marketplace-stock.pemetaan-hq.simpan'))->assertForbidden();
    }
}
