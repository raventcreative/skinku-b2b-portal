<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\TiktokConnection;
use App\Models\User;
use App\Services\ImageService;
use App\Services\MarketplaceMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Varian ala Desty: master induk (konten level produk) + master anak per opsi (SKU/harga/stok/barcode/listing).
 * Simpan form = himpunan lengkap opsi; listing master tunggal dipindah ke varian pertama; konten/foto/nama varian
 * diambil dari induk; tautan listing ditolak di induk bervarian; katalog menampilkan varian di bawah induk.
 */
class VarianMasterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function admin(): User
    {
        return User::create(['name' => 'a', 'fullname' => 'A', 'username' => 'a'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE]);
    }

    private function induk(array $over = []): MarketplaceMaster
    {
        return MarketplaceMaster::create(array_merge(['master_sku' => 'SCR', 'name' => 'Body Scrub Salmon', 'description' => 'Scrub DNA salmon'], $over));
    }

    private function form(MarketplaceMaster $m, array $varian, array $over = []): array
    {
        return array_merge(['name' => $m->name, 'master_sku' => $m->master_sku, 'description' => $m->description, 'varian_ada' => '1', 'variant_type' => 'Qty', 'varian' => $varian], $over);
    }

    public function test_simpan_varian_membuat_mengubah_dan_menghapus_anak(): void
    {
        $m = $this->induk();
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('marketplace-stock.update', $m), $this->form($m, [
            ['name' => 'Scrub 1 Pcs', 'sku' => 'SCR-1', 'price' => '45000', 'stock' => '10', 'barcode' => ''],
            ['name' => 'Scrub 3 Pcs', 'sku' => 'SCR-3', 'price' => '120000', 'stock' => '4'],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $m->refresh();
        $this->assertSame('Qty', $m->variant_type);
        $v = $m->variants;
        $this->assertSame(['Scrub 1 Pcs', 'Scrub 3 Pcs'], $v->pluck('variant_name')->all());
        $this->assertSame('Body Scrub Salmon - Scrub 3 Pcs', $v[1]->name);
        $this->assertSame([10, 4], $v->pluck('base_stock')->all());
        $this->assertNotNull($v[0]->seeded_at);

        // Ubah opsi 1, hapus opsi 3, tambah opsi 6; id asing (master lain) TIDAK ikut diubah.
        $lain = $this->induk(['master_sku' => 'LAIN', 'name' => 'Lain']);
        $this->actingAs($admin)->put(route('marketplace-stock.update', $m), $this->form($m, [
            ['id' => $v[0]->id, 'name' => 'Scrub 1 Pcs', 'sku' => 'SCR-1', 'price' => '46000', 'stock' => '10'],
            ['id' => $lain->id, 'name' => 'Scrub 6 Pcs', 'sku' => 'SCR-6', 'price' => '200000', 'stock' => '2'],
        ]))->assertRedirect();

        $m->refresh();
        $this->assertSame(['Scrub 1 Pcs', 'Scrub 6 Pcs'], $m->variants->pluck('variant_name')->all());
        $this->assertSame(46000.0, (float) $m->variants[0]->base_price);
        $this->assertNull(MarketplaceMaster::find($v[1]->id));      // dihapus
        $this->assertSame('Lain', $lain->fresh()->name);           // guard IDOR
        $this->assertNull($lain->fresh()->parent_id);

        // Tabel dikosongkan → semua varian hilang, tipe varian dikosongkan.
        $this->actingAs($admin)->put(route('marketplace-stock.update', $m), $this->form($m, []))->assertRedirect();
        $this->assertSame(0, $m->variants()->count());
        $this->assertNull($m->fresh()->variant_type);
    }

    public function test_form_tanpa_kartu_varian_tak_menyentuh_varian(): void
    {
        $m = $this->induk();
        MarketplaceMaster::create(['parent_id' => $m->id, 'variant_name' => 'A', 'master_sku' => 'SCR-A', 'name' => 'x']);

        $this->actingAs($this->admin())->put(route('marketplace-stock.update', $m), ['name' => $m->name, 'master_sku' => 'SCR'])->assertRedirect();

        $this->assertSame(1, $m->variants()->count());
    }

    public function test_listing_master_tunggal_pindah_ke_varian_pertama_dengan_harga_stok_induk(): void
    {
        $m = $this->induk(['base_price' => 45000, 'base_stock' => 30]);
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'SCR', 'master_id' => $m->id]);

        $this->actingAs($this->admin())->put(route('marketplace-stock.update', $m), $this->form($m, [
            ['name' => '1 Pcs', 'sku' => 'SCR-1', 'price' => '', 'stock' => ''],
            ['name' => '3 Pcs', 'sku' => 'SCR-3', 'price' => '120000', 'stock' => '5'],
        ], ['price' => '45000', 'stock' => '30']))->assertSessionHas('status', fn ($s) => str_contains($s, 'dipindah ke varian pertama'));

        $pertama = $m->variants()->first();
        $this->assertSame($pertama->id, $l->fresh()->master_id);
        $this->assertSame(30, $pertama->base_stock);
        $this->assertSame(45000.0, (float) $pertama->base_price);
    }

    public function test_tautkan_ke_induk_bervarian_ditolak_ke_varian_boleh(): void
    {
        $m = $this->induk();
        $v = MarketplaceMaster::create(['parent_id' => $m->id, 'variant_name' => 'A', 'master_sku' => 'SCR-A', 'name' => 'x']);
        $l = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'X1']);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('marketplace-stock.kaitkan', $m), ['listing_ids' => [$l->id]])->assertSessionHas('error');
        $this->assertNull($l->fresh()->master_id);

        $this->actingAs($admin)->post(route('marketplace-stock.kaitkan', $v), ['listing_ids' => [$l->id]])->assertSessionMissing('error');
        $this->assertSame($v->id, $l->fresh()->master_id);
    }

    public function test_konten_varian_dari_induk_barcode_per_varian_dan_judul_untuk_produk_bervarian(): void
    {
        $m = $this->induk(['weight_g' => 200, 'tiktok_category_id' => '3', 'tiktok_attributes' => []]);
        app(ImageService::class)->attach($m, UploadedFile::fake()->image('induk.jpg'), MarketplaceMaster::MASTER_IMAGE);
        $a = MarketplaceMaster::create(['parent_id' => $m->id, 'variant_name' => '1 Pcs', 'master_sku' => 'SCR-1', 'name' => 'x', 'barcode' => '8991234567891']);
        $b = MarketplaceMaster::create(['parent_id' => $m->id, 'variant_name' => '3 Pcs', 'master_sku' => 'SCR-3', 'name' => 'y']);
        $la = MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'SCR-1', 'master_id' => $a->id, 'item_id' => 'P1', 'variation_id' => 'S1']);
        MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'SCR-3', 'master_id' => $b->id, 'item_id' => 'P1', 'variation_id' => 'S3']);
        $svc = app(MarketplaceMasterService::class);

        $p = $svc->buildContentPayload($a, 'tiktok', $la);
        $this->assertSame('Body Scrub Salmon', $p['title']);           // semua SKU produk = keluarga ini → judul induk
        $this->assertSame('Scrub DNA salmon', $p['description']);
        $this->assertSame('3', $p['category_id']);
        $this->assertSame('8991234567891', $p['skus'][0]['identifier_code']['code']); // barcode varian
        $this->assertSame($svc->photoHash($m), $svc->photoHash($a));    // foto induk

        // SKU lain produk itu tak tertaut ke keluarga ini → judul ditahan.
        MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'ASING', 'item_id' => 'P1', 'variation_id' => 'S9']);
        $this->assertArrayNotHasKey('title', $svc->buildContentPayload($a, 'tiktok', $la));
    }

    public function test_dorong_induk_mengirim_ke_listing_semua_varian(): void
    {
        TiktokConnection::create(['shop_id' => 's', 'shop_cipher' => 'c', 'access_token' => 't', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);
        Http::fake([
            '*/partial_edit*' => Http::response(['code' => 0, 'data' => []]),
            '*/inventory/update*' => Http::response(['code' => 0, 'data' => []]),
            '*/prices/update*' => Http::response(['code' => 0, 'data' => []]),
        ]);
        $m = $this->induk();
        foreach (['1' => 'S1', '3' => 'S3'] as $n => $sku) {
            $v = MarketplaceMaster::create(['parent_id' => $m->id, 'variant_name' => "{$n} Pcs", 'master_sku' => "SCR-{$n}", 'name' => 'x', 'base_stock' => 5, 'base_price' => 1000, 'seeded_at' => now()]);
            MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => "SCR-{$n}", 'master_id' => $v->id, 'item_id' => 'P1', 'variation_id' => $sku, 'warehouse_id' => 'W']);
        }

        $this->actingAs($this->admin())->post(route('marketplace-stock.master.konten', $m))->assertSessionMissing('error');

        Http::assertSentCount(6); // 2 listing × (stok + harga + konten)
        $this->assertSame(2, MarketplaceListing::where('last_content_status', 'ok')->count());
    }

    public function test_katalog_menampilkan_varian_di_bawah_induk_dan_hitungan_tanpa_anak(): void
    {
        $m = $this->induk(['variant_type' => 'Qty']);
        MarketplaceMaster::create(['parent_id' => $m->id, 'variant_name' => 'Scrub 3 Pcs', 'master_sku' => 'SCR-3', 'name' => 'Body Scrub Salmon - Scrub 3 Pcs', 'base_price' => 120000, 'base_stock' => 4]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('marketplace-stock.index'))->assertOk()
            ->assertSee('1 varian · Qty')
            ->assertSee('Scrub 3 Pcs')
            ->assertSee('SCR-3')
            ->assertSee('Semua <span', false);
        $this->assertSame(1, MarketplaceMaster::whereNull('parent_id')->count());

        $this->actingAs($admin)->get(route('marketplace-stock.edit', $m->variants()->first()))->assertRedirect(route('marketplace-stock.edit', $m));
        $this->actingAs($admin)->get(route('marketplace-stock.edit', $m))->assertOk()->assertSee('Varian Produk')->assertSee('value="SCR-3"', false);
    }
}
