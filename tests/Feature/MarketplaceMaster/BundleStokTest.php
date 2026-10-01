<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\MarketplaceMasterChannel;
use App\Models\Product;
use App\Models\ShopeeSkuMap;
use App\Models\TiktokSkuMap;
use App\Models\TiktokConnection;
use App\Models\User;
use App\Services\MarketplaceMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bundle ber-resep: stok bundle = min(floor(stok komponen / qty)), order bundle memotong stok komponen (qty × delta),
 * resep disimpan dari form (himpunan lengkap, komponen tak sah dilewati), stok manual bundle ditolak, katalog
 * menampilkan stok otomatis, master yang jadi isi bundling tak bisa dihapus.
 */
class BundleStokTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'a', 'fullname' => 'A', 'username' => 'a'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE]);
    }

    private function svc(): MarketplaceMasterService
    {
        return app(MarketplaceMasterService::class);
    }

    private function master(string $sku, ?int $stok, array $over = []): MarketplaceMaster
    {
        return MarketplaceMaster::create(array_merge(['master_sku' => $sku, 'name' => "Produk {$sku}", 'base_stock' => $stok, 'seeded_at' => now()->subDay()], $over));
    }

    private function bundle(array $isi): MarketplaceMaster
    {
        $b = $this->master('BND', 5, ['is_bundle' => true]);
        foreach ($isi as [$komponen, $qty]) {
            $b->bundleItems()->create(['component_id' => $komponen->id, 'qty' => $qty]);
        }

        return $b->fresh();
    }

    public function test_stok_bundle_dihitung_dari_komponen_terkecil(): void
    {
        $reina = $this->master('REI-1', 100);
        $soap = $this->master('SOAP', 7);
        $b = $this->bundle([[$reina, 3], [$soap, 1]]);

        $this->assertSame(7, $this->svc()->effectiveStock($b, 'tiktok'));
        $soap->update(['base_stock' => 50]);
        $this->assertSame(33, $this->svc()->effectiveStock($b->fresh(), 'tiktok')); // 100/3

        // Override channel komponen dipakai di channel itu.
        MarketplaceMasterChannel::create(['master_id' => $reina->id, 'channel' => 'shopee', 'stock' => 6]);
        $this->assertSame(2, $this->svc()->effectiveStock($b->fresh(), 'shopee'));

        $reina->update(['base_stock' => null]);
        $this->assertNull($this->svc()->effectiveStock($b->fresh(), 'tiktok')); // komponen tanpa stok → tak di-push
    }

    public function test_order_bundle_memotong_stok_komponen_dan_batal_mengembalikan(): void
    {
        $reina = $this->master('REI-1', 100);
        $b = $this->bundle([[$reina, 3]]);
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'BND', 'master_id' => $b->id]);

        $this->svc()->applyOrderDelta($l, -5, now());
        $this->assertSame(85, $reina->fresh()->base_stock);
        $this->assertSame(5, $b->fresh()->base_stock); // stok manual bundle tak disentuh

        $this->svc()->applyOrderDelta($l, 5, now()); // batal/retur
        $this->assertSame(100, $reina->fresh()->base_stock);

        $this->svc()->applyOrderDelta($l, -5, now()->subDays(3)); // order sebelum stok disetel → diabaikan
        $this->assertSame(100, $reina->fresh()->base_stock);
    }

    public function test_simpan_resep_dari_form_menyaring_komponen_tak_sah(): void
    {
        $reina = $this->master('REI-1', 100);
        $induk = $this->master('IND', null);
        MarketplaceMaster::create(['parent_id' => $induk->id, 'variant_name' => 'A', 'master_sku' => 'IND-A', 'name' => 'x']);
        $lainBundle = $this->bundle([[$reina, 2]]);
        $b = $this->master('REI-3', 18, ['is_bundle' => true]);
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('marketplace-stock.update', $b), [
            'name' => $b->name, 'master_sku' => 'REI-3', 'is_bundle' => '1', 'isi_bundle_ada' => '1',
            'isi_bundle' => [
                ['component_id' => $reina->id, 'qty' => 2], ['component_id' => $reina->id, 'qty' => 1], // digabung → 3
                ['component_id' => $induk->id, 'qty' => 1],      // induk bervarian → dilewati
                ['component_id' => $lainBundle->id, 'qty' => 1], // bundle ber-resep → dilewati
                ['component_id' => $b->id, 'qty' => 1],          // diri sendiri → dilewati
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame([[$reina->id, 3]], $b->bundleItems()->get()->map(fn ($x) => [$x->component_id, $x->qty])->all());
        $this->assertSame(33, $this->svc()->effectiveStock($b->fresh(), 'tiktok'));

        // Ganti tipe ke Satuan → resep dikosongkan.
        $this->actingAs($admin)->put(route('marketplace-stock.update', $b), ['name' => $b->name, 'master_sku' => 'REI-3', 'is_bundle' => '0', 'isi_bundle_ada' => '1'])->assertRedirect();
        $this->assertSame(0, $b->bundleItems()->count());
    }

    public function test_stok_manual_bundle_ditolak_dan_ubah_stok_komponen_mendorong_bundle(): void
    {
        TiktokConnection::create(['shop_id' => 's', 'shop_cipher' => 'c', 'access_token' => 't', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);
        Http::fake(['*' => Http::response(['code' => 0, 'data' => []])]);
        $reina = $this->master('REI-1', 100);
        $b = $this->bundle([[$reina, 3]]);
        $lb = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'BND', 'master_id' => $b->id, 'item_id' => 'P9', 'variation_id' => 'S9', 'warehouse_id' => 'W']);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('marketplace-stock.master.stok', $b), ['quantity' => 50])->assertSessionHas('error');
        $this->assertSame(5, $b->fresh()->base_stock);

        $this->actingAs($admin)->post(route('marketplace-stock.master.stok', $reina), ['quantity' => 30])->assertSessionMissing('error');
        $this->assertSame(10, $lb->fresh()->last_pushed_qty); // 30/3 langsung terdorong ke listing bundle
        Http::assertSent(fn ($req) => str_contains($req->url(), '/products/P9/inventory/update'));
    }

    public function test_katalog_stok_otomatis_dan_hapus_komponen_ditolak(): void
    {
        $reina = $this->master('REI-1', 100);
        $b = $this->bundle([[$reina, 3]]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('marketplace-stock.index'))->assertOk()->assertSee('otomatis')->assertSeeInOrder(['Produk BND', '33']);

        $this->actingAs($admin)->delete(route('marketplace-stock.master.hapus', $reina))->assertSessionHas('error');
        $this->assertNotNull($reina->fresh());

        $this->actingAs($admin)->get(route('marketplace-stock.edit', $b))->assertOk()->assertSee('Isi Bundling')->assertSee('Produk REI-1 (REI-1)');
    }

    public function test_ambil_resep_dari_sku_map_hq(): void
    {
        $reinaHq = Product::create(['name' => 'Reina Cream', 'sku' => 'RC-HQ', 'status' => 'active']);
        $soapHq = Product::create(['name' => 'Body Soap', 'sku' => 'SOAP-HQ', 'status' => 'active']);
        $serumHq = Product::create(['name' => 'Body Serum', 'sku' => 'SER-HQ', 'status' => 'active']);

        // Reina: dicocokkan lewat listing satuan yg SKU map-nya Reina ×1. Soap: lewat master_sku = SKU produk HQ.
        $reina = $this->master('REI-1', 100);
        MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'TT-REI-1', 'master_id' => $reina->id]);
        TiktokSkuMap::create(['tiktok_sku' => 'TT-REI-1', 'product_id' => $reinaHq->id, 'qty' => 1]);
        $soap = $this->master('SOAP-HQ', 50);

        $b = $this->master('REI-3', 18, ['is_bundle' => true]);
        MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'TT-REI-3', 'master_id' => $b->id]);
        TiktokSkuMap::create(['tiktok_sku' => 'TT-REI-3', 'product_id' => $reinaHq->id, 'qty' => 3]);
        TiktokSkuMap::create(['tiktok_sku' => 'TT-REI-3', 'product_id' => $soapHq->id, 'qty' => 1]);
        TiktokSkuMap::create(['tiktok_sku' => 'TT-REI-3', 'product_id' => $serumHq->id, 'qty' => 2]);

        $this->actingAs($this->admin())->getJson(route('marketplace-stock.master.resep-hq', $b))->assertOk()
            ->assertJsonPath('data.sumber', 'Tiktok SKU TT-REI-3')
            ->assertJsonPath('data.rows.0.component_id', $reina->id)->assertJsonPath('data.rows.0.qty', 3)
            ->assertJsonPath('data.rows.1.component_id', $soap->id)
            ->assertJsonPath('data.gagal', ['Body Serum ×2'])
            ->assertJsonPath('data.kosong', [['qty' => 2, 'nama' => 'Body Serum']]);

        // Tanpa listing: dicoba lewat master_sku sbg SKU Shopee.
        $c = $this->master('SP-BND', 1, ['is_bundle' => true]);
        ShopeeSkuMap::create(['shopee_sku' => 'SP-BND', 'product_id' => $soapHq->id, 'qty' => 2]);
        $this->assertSame([['component_id' => $soap->id, 'qty' => 2, 'label' => 'Produk SOAP-HQ (SOAP-HQ)']], $this->svc()->resepDariHq($c)['rows']);

        $this->assertNull($this->svc()->resepDariHq($this->master('X', 1))['sumber']); // tak ada resep HQ
    }

    public function test_penanda_produk_hq_disimpan_dari_form_dan_dipakai_mencocokkan_resep(): void
    {
        $reinaHq = Product::create(['name' => 'REINA 30g', 'sku' => 'RN30', 'status' => 'active']);
        $reina = $this->master('REI-1', 533);
        $b = $this->master('REI-3', 18, ['is_bundle' => true]);
        TiktokSkuMap::create(['tiktok_sku' => 'REI-3', 'product_id' => $reinaHq->id, 'qty' => 3]);
        $admin = $this->admin();

        $this->assertSame([], $this->svc()->resepDariHq($b)['rows']); // belum ditandai → tak cocok

        $this->actingAs($admin)->put(route('marketplace-stock.update', $reina), ['name' => $reina->name, 'master_sku' => 'REI-1', 'product_id' => $reinaHq->id])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($reinaHq->id, $reina->fresh()->product_id);
        $this->assertSame(533, $reina->fresh()->base_stock); // penanda saja — stok tak tersentuh
        $this->assertSame(533, $reina->fresh()->base_stock);

        $this->assertSame([['component_id' => $reina->id, 'qty' => 3, 'label' => 'Produk REI-1 (REI-1)']], $this->svc()->resepDariHq($b)['rows']);

        // Form tanpa field product_id tak menghapus penanda; kosongkan eksplisit → null.
        $this->actingAs($admin)->put(route('marketplace-stock.update', $reina), ['name' => $reina->name, 'master_sku' => 'REI-1'])->assertRedirect();
        $this->assertSame($reinaHq->id, $reina->fresh()->product_id);
        $this->actingAs($admin)->put(route('marketplace-stock.update', $reina), ['name' => $reina->name, 'master_sku' => 'REI-1', 'product_id' => ''])->assertRedirect();
        $this->assertNull($reina->fresh()->product_id);

        $this->actingAs($admin)->get(route('marketplace-stock.edit', $reina))->assertOk()->assertSee('Produk HQ')->assertSee('REINA 30g (RN30)');
    }
}
