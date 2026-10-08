<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\MarketplaceListing;
use App\Models\ShopeeBoostItem;
use App\Models\ShopeeConnection;
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

    private function fakeShopee(array $sedangNaik, array $berhasil, array $gagal = []): void
    {
        Http::fake([
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
            ->assertSee('Naikkan Produk Otomatis')->assertSee('0/5 terpakai')->assertSee('Serum Glow')->assertSee('○ MATI');
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

        $this->assertSame(['status' => 'ok', 'naik' => 1, 'gagal' => 1, 'sedang_naik' => 1], $hasil);
        // 1001 masih naik → tak ikut dikirim; hanya 1002 & 1003 yang dinaikkan.
        Http::assertSent(fn ($req) => str_contains($req->url(), 'boost_item') && $req['item_id_list'] === [1002, 1003]);
        $item = fn (int $id) => ShopeeBoostItem::where('item_id', $id)->first();
        $this->assertSame('2026-10-08 11:00:00', $item(1001)->boosted_until->toDateTimeString());
        $this->assertSame(['ok', '2026-10-08 10:00:00', '2026-10-08 14:00:00'],
            [$item(1002)->last_status, $item(1002)->last_boosted_at->toDateTimeString(), $item(1002)->boosted_until->toDateTimeString()]);
        $this->assertSame(['failed', 'can not boost item repeatedly'], [$item(1003)->last_status, $item(1003)->last_error]);

        // Halaman menampilkan status per produk.
        $this->actingAs($this->user(User::ROLE_ADMIN))->get(route('shopee-naikkan.index'))->assertOk()
            ->assertSee('Sedang naik · sisa 1j 0m')->assertSee('Sedang naik · sisa 4j 0m')->assertSee('can not boost item repeatedly');
    }

    public function test_saklar_mati_tak_memanggil_shopee_kecuali_tombol_jalankan_sekarang(): void
    {
        $this->pilih(1001);
        $this->fakeShopee([], [1001]);

        $this->assertSame(['status' => 'nonaktif'], app(ShopeeBoostService::class)->jalankan());
        Http::assertNothingSent();

        $this->actingAs($this->user(User::ROLE_ADMIN))->post(route('shopee-naikkan.jalankan'))
            ->assertSessionHas('status', 'Selesai: 1 produk dinaikkan, 0 masih dalam masa naik.');
        $this->assertSame('ok', ShopeeBoostItem::first()->last_status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'shopee_naikkan_jalankan']);
    }

    public function test_error_shopee_dicatat_di_produk_dan_command_gagal(): void
    {
        AppSetting::put(ShopeeBoostService::KUNCI_AKTIF, '1');
        $this->pilih(1001);
        Http::fake(['*product/get_boosted_list*' => Http::response(['error' => 'error_auth', 'message' => 'Invalid access_token'])]);

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
