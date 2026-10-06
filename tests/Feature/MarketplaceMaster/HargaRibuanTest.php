<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\MarketplaceMasterChannel;
use App\Models\User;
use App\Support\Rupiah;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Input harga bertitik ribuan ("195.000") di katalog, halaman channel & form master (+ varian). Browser mengirim
 * angka polos (partials/rupiah-input); server tetap menormalkan sbg jaring pengaman — tanpa itu "65.000" lolos
 * `numeric` sebagai 65 (seribu kali lebih kecil, tanpa error).
 */
class HargaRibuanTest extends TestCase
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

    private function master(array $over = []): MarketplaceMaster
    {
        return MarketplaceMaster::create(array_merge(['master_sku' => 'HR-1', 'name' => 'Serum X'], $over));
    }

    public function test_rupiah_input_menampilkan_titik_ribuan_tanpa_sen(): void
    {
        $this->assertSame('195.000', Rupiah::input(195000));
        $this->assertSame('150.000', Rupiah::input('150000.00'));
        $this->assertSame('1.500.000', Rupiah::input(1500000));
        $this->assertSame('0', Rupiah::input(0));
        $this->assertSame('', Rupiah::input(null));
        $this->assertSame('', Rupiah::input(''));
        $this->assertSame('', Rupiah::input('abc'));
    }

    public function test_rupiah_polos_hanya_mengubah_pola_bertitik_ribuan(): void
    {
        $this->assertSame('195000', Rupiah::polos('195.000'));
        $this->assertSame('1500000', Rupiah::polos('1.500.000'));
        $this->assertSame('65000', Rupiah::polos('Rp 65.000'));
        $this->assertSame('65000', Rupiah::polos('Rp.65.000'));
        // Bukan pola bertitik ribuan → apa adanya (desimal biasa tetap desimal).
        $this->assertSame('195000', Rupiah::polos('195000'));
        $this->assertSame('195000.00', Rupiah::polos('195000.00'));
        $this->assertSame('12.50', Rupiah::polos('12.50'));
        $this->assertSame('1.5', Rupiah::polos('1.5'));
        $this->assertNull(Rupiah::polos(null));
    }

    public function test_harga_katalog_bertitik_tersimpan_utuh_bukan_seribu_kali_lebih_kecil(): void
    {
        $m = $this->master();

        $this->actingAs($this->admin())->post(route('marketplace-stock.master.harga', $m), ['price' => '195.000'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEquals(195000, $m->fresh()->base_price);
    }

    public function test_harga_katalog_angka_polos_tetap_jalan(): void
    {
        $m = $this->master();

        $this->actingAs($this->admin())->post(route('marketplace-stock.master.harga', $m), ['price' => '65000'])->assertRedirect();

        $this->assertEquals(65000, $m->fresh()->base_price);
    }

    public function test_harga_channel_bertitik_tersimpan_utuh(): void
    {
        $m = $this->master();

        $this->actingAs($this->admin())
            ->post(route('marketplace-stock.channel.harga', ['channel' => 'tiktok', 'master' => $m]), ['price' => '1.500.000'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEquals(1500000, MarketplaceMasterChannel::where('master_id', $m->id)->where('channel', 'tiktok')->value('price'));
    }

    public function test_form_master_harga_bertitik_tersimpan_utuh(): void
    {
        $m = $this->master();

        $this->actingAs($this->admin())->put(route('marketplace-stock.update', $m), [
            'name' => 'Serum X', 'master_sku' => 'HR-1', 'price' => '65.000',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEquals(65000, $m->fresh()->base_price);
    }

    public function test_form_harga_varian_bertitik_tersimpan_utuh(): void
    {
        $m = $this->master(['description' => 'Serum wajah']);

        $this->actingAs($this->admin())->put(route('marketplace-stock.update', $m), [
            'name' => 'Serum X', 'master_sku' => 'HR-1', 'description' => 'Serum wajah',
            'varian_ada' => '1', 'variant_type' => 'Qty',
            'varian' => [
                ['name' => '1 Pcs', 'sku' => 'HR-1P', 'price' => '50.000', 'stock' => '10'],
                ['name' => '3 Pcs', 'sku' => 'HR-3P', 'price' => '150.000', 'stock' => '4'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEquals([50000, 150000], $m->fresh()->variants->pluck('base_price')->map(fn ($p) => (float) $p)->all());
    }

    public function test_tampilan_katalog_channel_dan_form_bertitik_dan_skrip_sekali(): void
    {
        $m = $this->master(['base_price' => 195000]);
        $this->master(['master_sku' => 'HR-2', 'name' => 'Toner Y', 'base_price' => 65000]);
        MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'HR-1', 'item_id' => 'P1', 'master_id' => $m->id]);
        MarketplaceMasterChannel::create(['master_id' => $m->id, 'channel' => 'tiktok', 'price' => 1500000]);
        $admin = $this->admin();

        $katalog = $this->actingAs($admin)->get(route('marketplace-stock.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input type="text" inputmode="numeric" data-rupiah name="price" value="195\.000"/', $katalog);
        $this->assertStringContainsString('value="65.000"', $katalog);
        $this->assertSame(1, substr_count($katalog, "addEventListener('formdata'"), 'skrip rupiah harus dimuat SEKALI walau banyak baris');

        $channel = $this->actingAs($admin)->get(route('marketplace-stock.channel', 'tiktok'))->assertOk()->getContent();
        $this->assertStringContainsString('data-rupiah name="price" value="1.500.000"', $channel);

        $form = $this->actingAs($admin)->get(route('marketplace-stock.edit', $m))->assertOk()->getContent();
        $this->assertStringContainsString('data-rupiah name="price" value="195.000"', $form);
        $this->assertStringContainsString('data-rupiah id="varSemuaHarga"', $form);
        $this->assertStringContainsString("'data-rupiah': ''", $form); // baris varian baru dari JS ikut terformat
    }
}
