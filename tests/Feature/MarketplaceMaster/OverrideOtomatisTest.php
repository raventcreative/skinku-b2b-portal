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

/**
 * Override stok/harga TikTok/Shopee diisi langsung di tabel halaman channel: tersimpan otomatis (JSON) + langsung
 * dikirim ke marketplace, kosong = ikut master, isian ngawur ditolak 422 tanpa mengubah angka lama, tercatat di Audit Log.
 */
class OverrideOtomatisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['code' => 0, 'data' => []])]);
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'fullname' => strtoupper($role), 'username' => $role.uniqid(), 'email' => uniqid().'@t.test',
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE]);
    }

    private function master(): MarketplaceMaster
    {
        $m = MarketplaceMaster::create(['master_sku' => 'SCR-1', 'name' => 'Scrub 1 Pcs', 'base_stock' => 20, 'base_price' => 50000]);
        MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'SCR-1', 'item_id' => 'P1', 'master_id' => $m->id]);

        return $m;
    }

    private function simpan(MarketplaceMaster $m, array $isian, string $role = User::ROLE_ADMIN)
    {
        return $this->actingAs($this->user($role))->postJson(route('marketplace-stock.override', ['channel' => 'tiktok', 'master' => $m]), $isian);
    }

    private function override(MarketplaceMaster $m): ?MarketplaceMasterChannel
    {
        return MarketplaceMasterChannel::where('master_id', $m->id)->where('channel', 'tiktok')->first();
    }

    public function test_override_stok_diisi_di_tabel_tersimpan_otomatis(): void
    {
        $m = $this->master();
        $url = route('marketplace-stock.override', ['channel' => 'tiktok', 'master' => $m]);

        $this->actingAs($this->user(User::ROLE_ADMIN))->get(route('marketplace-stock.channel', 'tiktok'))->assertOk()
            ->assertSee('data-override="stock"', false)->assertSee('data-override="price"', false)->assertSee($url);

        $res = $this->simpan($m, ['field' => 'stock', 'value' => '80'])->assertOk()->assertJson(['override' => '80', 'efektif' => '80']);
        $this->assertStringContainsString('data-kirim="stok"', $res->json('status')); // status kirim baris ikut diperbarui
        $this->assertSame(80, $this->override($m)->stock);
        $this->assertDatabaseHas('audit_logs', ['action' => 'update_marketplace_override', 'target_type' => 'marketplace_master', 'target_id' => $m->id]);
    }

    public function test_override_harga_bertitik_lalu_dikosongkan_kembali_ikut_master(): void
    {
        $m = $this->master();

        $this->simpan($m, ['field' => 'price', 'value' => '145.000'])->assertOk()->assertJson(['override' => '145.000', 'efektif' => 'Rp145.000']);
        $this->assertSame(145000.0, (float) $this->override($m)->price);

        $this->simpan($m, ['field' => 'price', 'value' => ''])->assertOk()->assertJson(['override' => null, 'efektif' => 'Rp50.000']);
        $this->assertNull($this->override($m)); // stok & harga dua-duanya ikut master → baris override dihapus
    }

    public function test_isian_ngawur_ditolak_422_dan_angka_lama_tetap(): void
    {
        $m = $this->master();
        MarketplaceMasterChannel::create(['master_id' => $m->id, 'channel' => 'tiktok', 'stock' => 5]);

        foreach ([['field' => 'stock', 'value' => '-3'], ['field' => 'stock', 'value' => 'abc'], ['field' => 'stock', 'value' => '2.5'],
            ['field' => 'hack', 'value' => '9'], ['field' => 'stock', 'value' => ['9']]] as $isian) {
            $this->simpan($m, $isian)->assertStatus(422)->assertJsonStructure(['message']);
        }
        $this->assertSame(5, $this->override($m)->stock);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'update_marketplace_override']);
    }

    public function test_tanpa_izin_kelola_stok_marketplace_ditolak(): void
    {
        $m = $this->master();

        $this->simpan($m, ['field' => 'stock', 'value' => '99'], User::ROLE_GUDANG)->assertForbidden();
        $this->assertNull($this->override($m));
    }
}
