<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\ShopeeConnection;
use App\Models\TiktokConnection;
use App\Models\User;
use App\Services\MarketplaceMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Kategori marketplace per channel: pohon kategori (cari daun + jalur), atribut kategori (TikTok tanpa
 * SALES_PROPERTY; Shopee input_type + merek), tarik dari listing, simpan dari form (disaring), dan ikut
 * terkirim di payload konten (kategori + atribut selalu bersama; konflik varian beda-master ditahan).
 */
class KategoriMarketplaceTest extends TestCase
{
    use RefreshDatabase;

    private const TT_ATTR = '*/product/202309/categories/*/attributes*';
    private const TT_CAT = '*/product/202309/categories*';
    private const TT_PRODUCT = '*/product/202309/products/PID1?*';
    private const TT_EDIT = '*/product/202309/products/*/partial_edit*';
    private const SP_CAT = '*/api/v2/product/get_category*';
    private const SP_ATTR = '*/api/v2/product/get_attribute_tree*';
    private const SP_BRAND = '*/api/v2/product/get_brand_list*';
    private const SP_BASE = '*/api/v2/product/get_item_base_info*';
    private const SP_UPDATE = '*/api/v2/product/update_item*';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        TiktokConnection::create(['shop_id' => 's', 'shop_cipher' => 'c', 'access_token' => 't', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);
        ShopeeConnection::create(['shop_id' => '123', 'access_token' => 't', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);
    }

    private function svc(): MarketplaceMasterService
    {
        return app(MarketplaceMasterService::class);
    }

    private function user(string $role): User
    {
        return User::create(['name' => 'u', 'fullname' => 'U', 'username' => 'u'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE]);
    }

    private function fakeApi(array $extra = []): void
    {
        Http::preventStrayRequests();
        Http::fake($extra + [
            self::TT_ATTR => Http::response(['code' => 0, 'data' => ['attributes' => [
                ['id' => '100', 'name' => 'Merek', 'type' => 'PRODUCT_PROPERTY', 'is_requried' => true, 'is_customizable' => true, 'is_multiple_selection' => false, 'values' => [['id' => '7', 'name' => 'SKINKU']]],
                ['id' => '200', 'name' => 'Warna', 'type' => 'SALES_PROPERTY', 'values' => []],
                ['id' => '300', 'name' => 'Isi', 'type' => 'PRODUCT_PROPERTY', 'values' => [['id' => '31', 'name' => '30ml'], ['id' => '32', 'name' => '50ml']]],
            ]]]),
            self::TT_CAT => Http::response(['code' => 0, 'data' => ['categories' => [
                ['id' => '1', 'parent_id' => '0', 'local_name' => 'Kecantikan', 'is_leaf' => false],
                ['id' => '2', 'parent_id' => '1', 'local_name' => 'Perawatan Mulut', 'is_leaf' => false],
                ['id' => '3', 'parent_id' => '2', 'local_name' => 'Penyegar Napas', 'is_leaf' => true],
                ['id' => '4', 'parent_id' => '1', 'local_name' => 'Sabun Mandi', 'is_leaf' => true],
            ]]]),
            self::TT_PRODUCT => Http::response(['code' => 0, 'data' => [
                'category_chains' => [['id' => '1', 'local_name' => 'Kecantikan', 'is_leaf' => false], ['id' => '3', 'local_name' => 'Penyegar Napas', 'is_leaf' => true]],
                'product_attributes' => [['id' => '100', 'name' => 'Merek', 'values' => [['id' => '7', 'name' => 'SKINKU']]]],
            ]]),
            self::TT_EDIT => Http::response(['code' => 0, 'data' => []]),
            self::SP_CAT => Http::response(['error' => '', 'response' => ['category_list' => [
                ['category_id' => 10, 'parent_category_id' => 0, 'original_category_name' => 'Beauty', 'display_category_name' => 'Kecantikan', 'has_children' => true],
                ['category_id' => 11, 'parent_category_id' => 10, 'original_category_name' => 'Mouth', 'display_category_name' => 'Perawatan Mulut', 'has_children' => false],
            ]]]),
            self::SP_ATTR => Http::response(['error' => '', 'response' => ['list' => [['category_id' => 11, 'attribute_tree' => [
                ['attribute_id' => 50, 'name' => 'Volume', 'mandatory' => true, 'multi_lang' => [['language' => 'id', 'value' => 'Volume']],
                    'attribute_info' => ['input_type' => 3, 'attribute_unit_list' => ['ml', 'L']], 'attribute_value_list' => []],
                ['attribute_id' => 51, 'name' => 'Masa Simpan', 'mandatory' => false, 'attribute_info' => ['input_type' => 1],
                    'attribute_value_list' => [['value_id' => 9, 'name' => '24 Months', 'multi_lang' => [['language' => 'id', 'value' => '24 Bulan']]]]],
            ]]]]]),
            self::SP_BRAND => Http::response(['error' => '', 'response' => ['brand_list' => [['brand_id' => 77, 'original_brand_name' => 'SKINKU', 'display_brand_name' => 'SKINKU']], 'has_next_page' => false, 'is_mandatory' => true]]),
            self::SP_BASE => Http::response(['error' => '', 'response' => ['item_list' => [[
                'item_id' => 555, 'category_id' => 11,
                'attribute_list' => [['attribute_id' => 50, 'attribute_value_list' => [['value_id' => 0, 'original_value_name' => '30', 'value_unit' => 'ml']]]],
                'brand' => ['brand_id' => 77, 'original_brand_name' => 'SKINKU'],
            ]]]]),
            self::SP_UPDATE => Http::response(['error' => '', 'response' => []]),
        ]);
    }

    private function master(array $over = []): MarketplaceMaster
    {
        return MarketplaceMaster::create(array_merge(['master_sku' => 'HK-1', 'name' => 'Hakka Mouth Spray'], $over));
    }

    private function listing(MarketplaceMaster $m, string $channel, array $over = []): MarketplaceListing
    {
        return MarketplaceListing::create(array_merge($channel === 'tiktok'
            ? ['channel' => 'tiktok', 'seller_sku' => 'HK-1', 'master_id' => $m->id, 'item_id' => 'PID1', 'variation_id' => 'SKU1']
            : ['channel' => 'shopee', 'seller_sku' => 'HK-1', 'master_id' => $m->id, 'item_id' => '555', 'variation_id' => '0'], $over));
    }

    public function test_cari_kategori_hanya_daun_dengan_jalur_lengkap_dan_di_cache(): void
    {
        $this->fakeApi();

        $this->assertSame([['id' => '3', 'path' => 'Kecantikan > Perawatan Mulut > Penyegar Napas']], $this->svc()->cariKategori('tiktok', 'mulut napas'));
        $this->assertCount(2, $this->svc()->cariKategori('tiktok', ''));
        $this->assertSame([['id' => '11', 'path' => 'Kecantikan > Perawatan Mulut']], $this->svc()->cariKategori('shopee', 'MULUT'));

        $this->svc()->cariKategori('tiktok', 'sabun'); // pohon dari cache — tak memanggil API lagi
        Http::assertSentCount(2);
    }

    public function test_atribut_tiktok_tanpa_sales_property_dan_shopee_dengan_unit_dan_merek(): void
    {
        $this->fakeApi();

        $tt = $this->svc()->atributKategori('tiktok', '3');
        $this->assertSame(['100', '300'], array_column($tt['attributes'], 'id'));
        $this->assertTrue($tt['attributes'][0]['required']);
        $this->assertTrue($tt['attributes'][0]['custom']);
        $this->assertNull($tt['brands']);

        $sp = $this->svc()->atributKategori('shopee', '11');
        $this->assertSame(['id' => '50', 'name' => 'Volume', 'required' => true, 'multi' => false, 'custom' => true, 'units' => ['ml', 'L'], 'values' => []], $sp['attributes'][0]);
        $this->assertSame([['id' => '9', 'name' => '24 Bulan']], $sp['attributes'][1]['values']);
        $this->assertFalse($sp['attributes'][1]['custom']);
        $this->assertSame([['id' => '77', 'name' => 'SKINKU']], $sp['brands']);
        $this->assertTrue($sp['brand_required']);
    }

    public function test_tarik_kategori_dari_listing_tiktok_dan_shopee(): void
    {
        $this->fakeApi();
        $m = $this->master();
        $this->listing($m, 'tiktok');
        $this->listing($m, 'shopee');

        $this->assertSame(['tiktok' => 'ok', 'shopee' => 'ok'], $this->svc()->tarikKategori($m));

        $m->refresh();
        $this->assertSame('3', $m->tiktok_category_id);
        $this->assertSame('Kecantikan > Penyegar Napas', $m->tiktok_category_name);
        $this->assertSame([['id' => '100', 'values' => [['id' => '7', 'name' => 'SKINKU']]]], $m->tiktok_attributes);
        $this->assertSame('11', $m->shopee_category_id);
        $this->assertSame('Kecantikan > Perawatan Mulut', $m->shopee_category_name);
        $this->assertSame([['id' => '50', 'values' => [['id' => '', 'name' => '30', 'unit' => 'ml']]]], $m->shopee_attributes);
        $this->assertSame(['brand_id' => 77, 'original_brand_name' => 'SKINKU'], $m->shopee_brand);
    }

    public function test_payload_kategori_dan_atribut_per_channel(): void
    {
        $m = $this->master([
            'tiktok_category_id' => '3', 'tiktok_attributes' => [['id' => '100', 'values' => [['id' => '7', 'name' => 'SKINKU']]], ['id' => '300', 'values' => [['id' => '', 'name' => '30ml']]]],
            'shopee_category_id' => '11', 'shopee_attributes' => [['id' => '50', 'values' => [['id' => '', 'name' => '30', 'unit' => 'ml']]], ['id' => '51', 'values' => [['id' => '9', 'name' => '24 Bulan']]]],
            'shopee_brand' => ['brand_id' => 77, 'original_brand_name' => 'SKINKU'],
        ]);
        $tt = $this->svc()->buildContentPayload($m, 'tiktok', $this->listing($m, 'tiktok'));
        $sp = $this->svc()->buildContentPayload($m, 'shopee', $this->listing($m, 'shopee'));

        $this->assertSame('3', $tt['category_id']);
        $this->assertSame([['id' => '100', 'values' => [['id' => '7', 'name' => 'SKINKU']]], ['id' => '300', 'values' => [['name' => '30ml']]]], $tt['product_attributes']);
        $this->assertSame(11, $sp['category_id']);
        $this->assertSame([
            ['attribute_id' => 50, 'attribute_value_list' => [['value_id' => 0, 'original_value_name' => '30', 'value_unit' => 'ml']]],
            ['attribute_id' => 51, 'attribute_value_list' => [['value_id' => 9, 'original_value_name' => '24 Bulan']]],
        ], $sp['attribute_list']);
        $this->assertSame(['brand_id' => 77, 'original_brand_name' => 'SKINKU'], $sp['brand']);
    }

    public function test_tanpa_kategori_tak_ada_field_kategori_dan_konflik_varian_beda_master_ditahan(): void
    {
        $m = $this->master();
        $l = $this->listing($m, 'tiktok');
        $this->assertArrayNotHasKey('category_id', $this->svc()->buildContentPayload($m, 'tiktok', $l));

        $m->update(['tiktok_category_id' => '3']);
        $lain = $this->master(['master_sku' => 'HK-2', 'tiktok_category_id' => '4']);
        $this->listing($lain, 'tiktok', ['seller_sku' => 'HK-2', 'variation_id' => 'SKU2']); // varian lain produk yg sama

        $this->assertArrayNotHasKey('category_id', $this->svc()->buildContentPayload($m, 'tiktok', $l));
        $lain->update(['tiktok_category_id' => '3']); // sepakat → boleh
        $this->assertSame('3', $this->svc()->buildContentPayload($m, 'tiktok', $l)['category_id']);
    }

    public function test_simpan_form_menyaring_atribut_lalu_sinkron_otomatis_mengirim_kategori(): void
    {
        $this->fakeApi();
        $m = $this->master();
        $l = $this->listing($m, 'tiktok', ['last_content_pushed_at' => now()]); // sudah pernah didorong manual

        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN))->put(route('marketplace-stock.update', $m), [
            'name' => 'Hakka Mouth Spray', 'master_sku' => 'HK-1',
            'tiktok_category_id' => '3', 'tiktok_category_name' => 'Kecantikan > Penyegar Napas',
            'tiktok_attributes' => json_encode([['id' => '100', 'values' => [['id' => '7', 'name' => 'SKINKU']]], ['id' => 'x', 'values' => [['name' => 'jahat']]], ['id' => '300', 'values' => [['id' => '', 'name' => '']]]]),
        ])->assertRedirect()->assertSessionMissing('error');

        $m->refresh();
        $this->assertSame('3', $m->tiktok_category_id);
        $this->assertSame([['id' => '100', 'values' => [['id' => '7', 'name' => 'SKINKU']]]], $m->tiktok_attributes); // id non-angka & nilai kosong dibuang
        $this->assertNull($m->shopee_category_id); // field Shopee tak dikirim → tak disentuh
        Http::assertSent(fn ($req) => str_contains($req->url(), '/products/PID1/partial_edit')
            && ($req->data()['category_id'] ?? null) === '3'
            && ($req->data()['product_attributes'][0]['id'] ?? null) === '100');
        $this->assertSame('ok', $l->fresh()->last_content_status);
    }

    public function test_endpoint_json_dan_tarik_butuh_izin(): void
    {
        $this->fakeApi();
        $m = $this->master();
        $admin = $this->user(User::ROLE_SUPER_ADMIN);

        $this->actingAs($admin)->getJson(route('marketplace-stock.kategori.cari', ['tiktok', 'q' => 'napas']))
            ->assertOk()->assertJsonPath('data.0.id', '3');
        $this->actingAs($admin)->getJson(route('marketplace-stock.kategori.atribut', ['shopee', '11']))
            ->assertOk()->assertJsonPath('data.brands.0.id', '77');
        $this->actingAs($admin)->get('/marketplace-stock/kategori/lazada/cari')->assertNotFound();
        $this->actingAs($admin)->post(route('marketplace-stock.master.kategori.tarik', $m))->assertSessionHas('error');

        $reseller = $this->user(User::ROLE_RESELLER);
        $this->actingAs($reseller)->getJson(route('marketplace-stock.kategori.cari', ['tiktok', 'q' => 'x']))->assertForbidden();
        $this->actingAs($reseller)->post(route('marketplace-stock.master.kategori.tarik', $m))->assertForbidden();
    }

    public function test_api_gagal_dikembalikan_sebagai_json_422_tanpa_token(): void
    {
        Http::fake([self::TT_CAT => Http::response(['code' => 105001, 'message' => 'access_token=RAHASIA invalid'], 200)]);

        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN))->getJson(route('marketplace-stock.kategori.cari', ['tiktok', 'q' => 'x']))
            ->assertStatus(422)->assertJson(fn ($j) => $j->where('error', fn ($e) => ! str_contains($e, 'RAHASIA'))->etc());
    }
}
