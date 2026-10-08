<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\MarketplaceListing;
use App\Models\ShopeeBoostItem;
use App\Models\ShopeeConnection;
use App\Models\ShopeeProduct;
use App\Models\User;
use App\Services\ShopeeBoostService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "Naikkan Produk" Shopee otomatis (ala Desty): maks 5 produk pilihan, putaran tiap 10 menit — cek yang masih naik
 * (get_boosted_list) lalu naikkan sisanya (boost_item); saklar ON/OFF; hasil & alasan gagal per produk; Audit Log.
 */
class ShopeeNaikkanProdukTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests(); // tak boleh ada panggilan Shopee sungguhan dari test
        ShopeeConnection::create(['shop_id' => '426938728', 'access_token' => 'A', 'refresh_token' => 'R',
            'access_expires_at' => now()->addHours(3), 'refresh_expires_at' => now()->addDays(20)]);
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'fullname' => strtoupper($role), 'username' => $role, 'email' => "{$role}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE]);
    }

    private function listing(string $channel, string $itemId, string $judul, string $sku): void
    {
        MarketplaceListing::create(['channel' => $channel, 'seller_sku' => $sku, 'item_id' => $itemId, 'title' => $judul]);
    }

    private function pilih(int ...$itemIds): void
    {
        foreach ($itemIds as $id) {
            ShopeeBoostItem::create(['item_id' => $id, 'title' => 'Produk '.$id]);
        }
    }

    /** Respons get_item_base_info: foto "https://cf.shopee.co.id/file/foto-{id}" utk item uji. */
    private function fotoShopee(): array
    {
        return ['*product/get_item_base_info*' => Http::response(['error' => '', 'response' => ['item_list' => array_map(
            fn ($id) => ['item_id' => $id, 'item_name' => "Produk {$id}", 'image' => ['image_url_list' => ["https://cf.shopee.co.id/file/foto-{$id}"]]],
            [...range(1001, 1006), ...range(9001, 9005)])]])];
    }

    private function fakeShopee(array $sedangNaik, array $berhasil, array $gagal = []): void
    {
        Http::fake($this->fotoShopee() + [
            '*product/get_boosted_list*' => Http::response(['error' => '', 'response' => ['item_list' => array_map(
                fn ($id, $detik) => ['item_id' => $id, 'cool_down_second' => $detik], array_keys($sedangNaik), $sedangNaik)]]),
            '*product/boost_item*' => Http::response(['error' => '', 'response' => [
                'success_list' => ['item_id_list' => $berhasil],
                'failure_list' => array_map(fn ($id, $alasan) => ['item_id' => $id, 'failed_reason' => $alasan], array_keys($gagal), $gagal),
            ]]),
        ]);
    }

    public function test_halaman_tombol_di_stok_shopee_dan_izin(): void
    {
        $this->listing('shopee', '1001', 'Serum Glow', 'SG-1');
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)->get(route('shopee-naikkan.index'))->assertOk()
            ->assertSee('Naikkan Produk Otomatis')->assertSee('0/5 terpakai')->assertSee('Serum Glow')->assertSee('○ MATI')
            ->assertSee('Cari nama produk atau SKU')->assertSee('data-cari="serum glow sg-1 1001"', false); // tambah lewat pencarian nama/SKU
        $this->actingAs($admin)->get(route('marketplace-stock.channel', 'shopee'))->assertOk()->assertSee(route('shopee-naikkan.index'));
        $this->actingAs($admin)->get(route('marketplace-stock.channel', 'tiktok'))->assertOk()->assertDontSee(route('shopee-naikkan.index'));
        $this->actingAs($this->user(User::ROLE_GUDANG))->get(route('shopee-naikkan.index'))->assertForbidden();
    }

    public function test_pilih_maksimal_5_produk_shopee_varian_digabung(): void
    {
        foreach (range(1001, 1006) as $id) {
            $this->listing('shopee', (string) $id, "Produk {$id}", "S-{$id}");
        }
        $this->listing('shopee', '1001', 'Produk 1001', 'S-1001-B'); // varian kedua item yang sama
        $this->listing('tiktok', '2001', 'Produk TikTok', 'T-1');
        $admin = $this->user(User::ROLE_ADMIN);
        $tambah = fn (int $id) => $this->actingAs($admin)->post(route('shopee-naikkan.tambah'), ['item_id' => $id]);

        $tambah(1001)->assertSessionHas('status');
        $tambah(1001)->assertSessionHas('error', 'Produk itu sudah dipilih.');
        $tambah(2001)->assertSessionHas('error');                // listing TikTok, bukan Shopee
        $tambah(9999)->assertSessionHas('error');                // tak ada di listing
        foreach ([1002, 1003, 1004, 1005] as $id) {
            $tambah($id)->assertSessionHas('status');
        }
        $tambah(1006)->assertSessionHas('error', 'Maksimal 5 produk (batas Shopee) — hapus salah satu dulu.');
        $this->assertSame([1001, 1002, 1003, 1004, 1005], ShopeeBoostItem::orderBy('id')->pluck('item_id')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'shopee_naikkan_tambah']);

        $this->actingAs($admin)->delete(route('shopee-naikkan.hapus', ShopeeBoostItem::where('item_id', 1003)->first()))->assertSessionHas('status');
        $this->assertSame(4, ShopeeBoostItem::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'shopee_naikkan_hapus']);
    }

    public function test_saklar_aktif_mati_dicatat(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)->post(route('shopee-naikkan.aktif'), ['aktif' => 1])->assertSessionHas('status');
        $this->assertTrue(app(ShopeeBoostService::class)->aktif());
        $this->actingAs($admin)->post(route('shopee-naikkan.aktif'), ['aktif' => 0]);
        $this->assertFalse(app(ShopeeBoostService::class)->aktif());
        $this->assertSame(2, AuditLog::where('action', 'shopee_naikkan_aktif')->count());
    }

    public function test_putaran_naikkan_hanya_yang_sudah_habis_masa_naiknya(): void
    {
        Carbon::setTestNow('2026-10-08 10:00:00');
        AppSetting::put(ShopeeBoostService::KUNCI_AKTIF, '1');
        $this->pilih(1001, 1002, 1003);
        $this->fakeShopee([1001 => 3600], [1002], [1003 => 'can not boost item repeatedly']);

        $hasil = app(ShopeeBoostService::class)->jalankan();

        $this->assertSame(['status' => 'ok', 'naik' => 1, 'gagal' => 1, 'sedang_naik' => 1, 'menunggu_slot' => 0], $hasil);
        // 1001 masih naik → tak ikut dikirim; 1002 & 1003 dinaikkan SATU PER SATU.
        Http::assertSent(fn ($req) => str_contains($req->url(), 'boost_item') && $req['item_id_list'] === [1002]);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'boost_item') && $req['item_id_list'] === [1003]);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'boost_item') && in_array(1001, $req['item_id_list'], true));
        $item = fn (int $id) => ShopeeBoostItem::where('item_id', $id)->first();
        $this->assertSame('2026-10-08 11:00:00', $item(1001)->boosted_until->toDateTimeString());
        $this->assertSame(['ok', '2026-10-08 10:00:00', '2026-10-08 14:00:00'],
            [$item(1002)->last_status, $item(1002)->last_boosted_at->toDateTimeString(), $item(1002)->boosted_until->toDateTimeString()]);
        $this->assertSame(['failed', 'can not boost item repeatedly'], [$item(1003)->last_status, $item(1003)->last_error]);

        // Halaman menampilkan status per produk + foto asli Shopee (diambil saat putaran, lalu di-cache).
        $this->actingAs($this->user(User::ROLE_ADMIN))->get(route('shopee-naikkan.index'))->assertOk()
            ->assertSee('Sedang naik · sisa 1j 0m')->assertSee('Sedang naik · sisa 4j 0m')->assertSee('can not boost item repeatedly')
            ->assertSee('https://cf.shopee.co.id/file/foto-1002');
        $this->assertSame('https://cf.shopee.co.id/file/foto-1001', ShopeeProduct::where('item_id', '1001')->value('image_url'));
    }

    public function test_slot_toko_dipakai_desty_hanya_kirim_sebanyak_slot_kosong(): void
    {
        // Kasus produksi 2026-10-08: Desty menaikkan 4 produk (sisa 175 menit), 5 produk dipilih di sini.
        Carbon::setTestNow('2026-10-08 14:13:00');
        AppSetting::put(ShopeeBoostService::KUNCI_AKTIF, '1');
        MarketplaceListing::create(['channel' => 'shopee', 'seller_sku' => 'D1', 'item_id' => '9001', 'title' => 'Produk Desty Satu']);
        $this->pilih(1001, 1002, 1003, 1004, 1005);
        $this->fakeShopee([9001 => 10500, 9002 => 10500, 9003 => 10500, 9004 => 10500], [1001]);

        $hasil = app(ShopeeBoostService::class)->jalankan();

        $this->assertSame(['status' => 'ok', 'naik' => 1, 'gagal' => 0, 'sedang_naik' => 0, 'menunggu_slot' => 4], $hasil);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'boost_item') && $req['item_id_list'] === [1001]); // 1 slot kosong
        $this->assertSame('ok', ShopeeBoostItem::where('item_id', 1001)->value('last_status'));
        $menunggu = ShopeeBoostItem::where('item_id', 1002)->first();
        $this->assertSame('penuh', $menunggu->last_status);
        $this->assertStringContainsString('penuh (5/5)', $menunggu->last_error); // slot kosong terakhir dipakai 1001 di putaran ini
        $this->assertStringContainsString('4 dipakai produk lain', $menunggu->last_error);
        $this->assertStringContainsString('±2j 55m', $menunggu->last_error); // slot Desty kosong lagi 175 menit

        $this->actingAs($this->user(User::ROLE_ADMIN))->get(route('shopee-naikkan.index'))->assertOk()
            ->assertSee('4 dari 5 slot toko dipakai produk lain')->assertSee('Produk Desty Satu')->assertSee('sisa 2j 55m')
            ->assertSee('Menunggu slot kosong')->assertSee('Sedang naik · sisa 4j 0m');
    }

    public function test_slot_penuh_total_tak_memanggil_boost_dan_error_slot_dari_shopee_jadi_menunggu(): void
    {
        AppSetting::put(ShopeeBoostService::KUNCI_AKTIF, '1');
        $this->pilih(1001);
        $this->fakeShopee([9001 => 600, 9002 => 600, 9003 => 600, 9004 => 600, 9005 => 600], []);

        $this->assertSame('slot_penuh', app(ShopeeBoostService::class)->jalankan()['status']);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'boost_item'));
        $this->assertSame('penuh', ShopeeBoostItem::first()->last_status);

        // Keduluan Desty di sela putaran → Shopee menolak "bump slot limit" → tetap "menunggu slot", bukan gagal.
        Http::fake($this->fotoShopee() + [
            '*product/get_boosted_list*' => Http::response(['error' => '', 'response' => ['item_list' => []]]),
            '*product/boost_item*' => Http::response(['error' => 'product.error_busi', 'message' => "reached shop's bump slot limit"]),
        ]);
        $this->assertSame('slot_penuh', app(ShopeeBoostService::class)->jalankan()['status']);
        $this->assertSame('penuh', ShopeeBoostItem::first()->last_status);
    }

    public function test_batas_slot_toko_lebih_kecil_dari_perkiraan_yang_muat_tetap_naik(): void
    {
        AppSetting::put(ShopeeBoostService::KUNCI_AKTIF, '1');
        $this->pilih(1001, 1002, 1003);
        Http::fake($this->fotoShopee() + [
            '*product/get_boosted_list*' => Http::response(['error' => '', 'response' => ['item_list' => []]]),
            '*product/boost_item*' => Http::sequence()
                ->push(['error' => '', 'response' => ['success_list' => ['item_id_list' => [1001]], 'failure_list' => []]])
                ->push(['error' => 'product.error_busi', 'message' => "reached shop's bump slot limit"]),
        ]);

        $hasil = app(ShopeeBoostService::class)->jalankan();

        $this->assertSame(['status' => 'ok', 'naik' => 1, 'gagal' => 0, 'sedang_naik' => 0, 'menunggu_slot' => 2], $hasil);
        $this->assertSame(['ok', 'penuh', 'penuh'], ShopeeBoostItem::orderBy('item_id')->pluck('last_status')->all());
        Http::assertSentCount(4); // foto + get_boosted_list + 2× boost_item — produk ketiga tak dicoba setelah Shopee bilang penuh
    }

    public function test_label_menunggu_menyebut_jam_putaran_berikutnya(): void
    {
        Carbon::setTestNow('2026-10-08 14:12:00');
        AppSetting::put(ShopeeBoostService::KUNCI_AKTIF, '1');
        $this->pilih(1001);

        $this->actingAs($this->user(User::ROLE_ADMIN))->get(route('shopee-naikkan.index'))->assertOk()->assertSee('Menunggu putaran 14.20');
    }

    public function test_saklar_mati_tak_memanggil_shopee_kecuali_tombol_jalankan_sekarang(): void
    {
        $this->pilih(1001);
        $this->fakeShopee([], [1001]);

        $this->assertSame(['status' => 'nonaktif'], app(ShopeeBoostService::class)->jalankan());
        Http::assertNothingSent();

        $admin = $this->user(User::ROLE_ADMIN);
        $this->actingAs($admin)->post(route('shopee-naikkan.jalankan'))
            ->assertSessionHas('status', 'Selesai: 1 produk dinaikkan, 0 masih dalam masa naik.');
        // Pesan tampil SEKALI (layout yang menampilkan; halaman tak mengulang).
        $html = $this->actingAs($admin)->from(route('shopee-naikkan.index'))->followingRedirects()->post(route('shopee-naikkan.jalankan'))->getContent();
        $this->assertSame(1, substr_count($html, 'Selesai: 1 produk dinaikkan, 0 masih dalam masa naik.'));
        $this->assertSame('ok', ShopeeBoostItem::first()->last_status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'shopee_naikkan_jalankan']);
    }

    public function test_error_shopee_dicatat_di_produk_dan_command_gagal(): void
    {
        AppSetting::put(ShopeeBoostService::KUNCI_AKTIF, '1');
        $this->pilih(1001);
        Http::fake($this->fotoShopee() + ['*product/get_boosted_list*' => Http::response(['error' => 'error_auth', 'message' => 'Invalid access_token'])]);

        $this->artisan('shopee:naikkan-produk')->assertExitCode(1);

        $item = ShopeeBoostItem::first();
        $this->assertSame('failed', $item->last_status);
        $this->assertStringContainsString('error_auth', $item->last_error);
    }

    public function test_dijadwalkan_tiap_10_menit(): void
    {
        $this->artisan('schedule:list')->assertSuccessful(); // memuat jadwal di routes/console.php
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'shopee:naikkan-produk'));

        $this->assertNotNull($event);
        $this->assertSame('*/10 * * * *', $event->expression);
    }
}
