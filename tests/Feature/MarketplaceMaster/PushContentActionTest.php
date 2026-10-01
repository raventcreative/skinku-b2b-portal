<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\ShopeeConnection;
use App\Models\TiktokConnection;
use App\Models\User;
use App\Services\ImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Aksi MANUAL "Dorong Konten ke Marketplace": route POST marketplace-stock.master.konten (grup izin
 * manage_marketplace_stock) + tombol di halaman Ubah & menu Atur katalog.
 *
 * Mesinnya (payload, diff-guard, tally) sudah diuji di PushContentTest. Di sini yang diuji:
 * kabel controller -> service, flash JUJUR (teks GAGAL khusus konten, bukan teks stok/harga),
 * izin, dan UI (form konten = <form> sendiri, tak bersarang).
 */
class PushContentActionTest extends TestCase
{
    use RefreshDatabase;

    /** Teks konfirmasi tombol — sama persis di halaman Ubah & menu Atur (& di-escape jadi &amp; di atribut HTML). */
    private const KONFIRMASI = 'Dorong SEMUA ke TikTok &amp; Shopee: stok, harga, nama, deskripsi, berat, dimensi, barcode &amp; FOTO. Isi listing DITIMPA dan SEMUA foto listing DIGANTI foto master. Setelah ini, tiap Simpan menyinkronkan otomatis. Lanjut?';

    // ---- helper ----

    private function admin(): User
    {
        return User::create(['name' => 'a', 'fullname' => 'A', 'username' => 'a'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE]);
    }

    private function reseller(): User
    {
        return User::create(['name' => 'r', 'fullname' => 'R', 'username' => 'r'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE]);
    }

    private function tiktokConn(): void
    {
        TiktokConnection::create(['shop_id' => 's', 'shop_cipher' => 'c', 'access_token' => 't', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);
    }

    private function shopeeConn(): void
    {
        ShopeeConnection::create(['shop_id' => '123', 'access_token' => 't', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);
    }

    /** Fake sukses utk dua endpoint konten; request lain (mis. stok/harga) = stray -> tercatat gagal. */
    private function fakeOk(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/product/202309/products/*/partial_edit*' => Http::response(['code' => 0, 'message' => 'Success', 'data' => []]),
            '*/api/v2/product/update_item*' => Http::response(['error' => '', 'message' => '', 'response' => []]),
        ]);
    }

    private function masterLengkap(): MarketplaceMaster
    {
        return MarketplaceMaster::create([
            'master_sku' => 'SX-1', 'name' => 'Serum X',
            'description' => 'Serum X', 'weight_g' => 250, 'length_cm' => 10, 'width_cm' => 8, 'height_cm' => 5,
        ]);
    }

    private function masterKosong(): MarketplaceMaster
    {
        return MarketplaceMaster::create(['master_sku' => 'K-1', 'name' => 'Kosong']);
    }

    private function tiktokListing(MarketplaceMaster $m, array $over = []): MarketplaceListing
    {
        return MarketplaceListing::create(array_merge([
            'channel' => 'tiktok', 'seller_sku' => 'SX-1', 'master_id' => $m->id,
            'item_id' => 'PID1', 'variation_id' => 'SKU1', 'warehouse_id' => 'WH1',
        ], $over));
    }

    private function shopeeListing(MarketplaceMaster $m, array $over = []): MarketplaceListing
    {
        return MarketplaceListing::create(array_merge([
            'channel' => 'shopee', 'seller_sku' => 'SX-1', 'master_id' => $m->id,
            'item_id' => '555', 'variation_id' => '66',
        ], $over));
    }

    /**
     * Kedalaman <form> di area <main>: [kedalaman akhir (0 = seimbang), kedalaman maks (1 = tak bersarang)].
     * Pola sama dgn CreateEditMasterTest::test_form_edit_tak_ada_form_bersarang.
     *
     * @return array{0:int,1:int}
     */
    private function kedalamanForm(string $html): array
    {
        preg_match_all('#<(/?)form\b#i', Str::between($html, '<main', '</main>'), $tags);
        $depth = 0;
        $max = 0;
        foreach ($tags[1] as $closing) {
            $depth += $closing === '/' ? -1 : 1;
            $max = max($max, $depth);
        }

        return [$depth, $max];
    }

    // ---- POST: kabel controller -> service + flash ----

    public function test_admin_dorong_konten_kirim_ke_semua_listing_dan_flash_hitungan(): void
    {
        $this->tiktokConn();
        $this->shopeeConn();
        $this->fakeOk();
        $m = $this->masterLengkap();
        $tiktok = $this->tiktokListing($m);
        $shopee = $this->shopeeListing($m);
        $hqSebelum = DB::table('stock_movements')->count();

        $this->actingAs($this->admin())->from(route('marketplace-stock.edit', $m))
            ->post(route('marketplace-stock.master.konten', $m))
            ->assertRedirect(route('marketplace-stock.edit', $m)) // back(): kembali ke halaman asal
            ->assertSessionHas('status', 'Dorong konten & foto "Serum X" (2 sinkron OK)')
            ->assertSessionMissing('error');

        // Service benar-benar jalan: 1 request per listing (TikTok partial_edit + Shopee update_item), jejak tercatat.
        Http::assertSentCount(2);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/products/PID1/partial_edit'));
        Http::assertSent(fn ($req) => str_contains($req->url(), '/api/v2/product/update_item'));
        $this->assertSame('ok', $tiktok->fresh()->last_content_status);
        $this->assertSame('ok', $shopee->fresh()->last_content_status);
        // HQ terisolasi: dorong konten tak menyentuh stock_movements.
        $this->assertSame($hqSebelum, DB::table('stock_movements')->count());
    }

    public function test_dorong_konten_manual_selalu_kirim_walau_konten_tak_berubah(): void
    {
        // Tombol = "kirim & TIMPA": force=true, jadi diff-guard (hash sama) TIDAK boleh melewati kiriman manual —
        // isi di marketplace mungkin sudah diubah orang di sana.
        $this->tiktokConn();
        $this->fakeOk();
        $m = $this->masterLengkap();
        $this->tiktokListing($m);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('marketplace-stock.master.konten', $m))
            ->assertSessionHas('status', 'Dorong konten & foto "Serum X" (1 sinkron OK)');
        $this->actingAs($admin)->post(route('marketplace-stock.master.konten', $m))
            ->assertSessionHas('status', 'Dorong konten & foto "Serum X" (1 sinkron OK)');

        Http::assertSentCount(2);
    }

    public function test_dorong_konten_gagal_flash_error_khusus_konten_bukan_teks_stok_harga(): void
    {
        $this->tiktokConn(); // Shopee sengaja BELUM terhubung -> 1 sukses, 1 gagal
        $this->fakeOk();
        $m = $this->masterLengkap();
        $this->tiktokListing($m);
        $shopee = $this->shopeeListing($m);

        $this->actingAs($this->admin())->post(route('marketplace-stock.master.konten', $m))
            ->assertRedirect()
            ->assertSessionHas('status', 'Dorong konten & foto "Serum X" (1 sinkron OK, 1 GAGAL)')
            // Teks GAGAL harus bicara KONTEN — bukan "stok/harga belum masuk" (menyesatkan utk jalur ini).
            ->assertSessionHas('error', '1 push ke marketplace GAGAL — konten/foto belum masuk. Buka Stok TikTok / Stok Shopee untuk lihat pesan error tiap listing.');

        $this->assertSame('failed', $shopee->fresh()->last_content_status);
    }

    public function test_dorong_konten_tanpa_listing_ber_item_id_flash_jujur_tanpa_http(): void
    {
        Http::fake();
        $m = $this->masterLengkap();
        $this->tiktokListing($m, ['item_id' => null]); // listing belum ke-resolve -> tak ikut dikirimi

        $this->actingAs($this->admin())->post(route('marketplace-stock.master.konten', $m))
            ->assertRedirect()
            // Jujur: tak ada yang dikirim — bukan status kosong/"berhasil".
            ->assertSessionHas('status', 'Konten & foto "Serum X" belum dikirim — belum ada listing marketplace tertaut (tautkan lewat "Tambah ke Marketplace")')
            ->assertSessionMissing('error');

        Http::assertNothingSent();
    }

    public function test_dorong_konten_master_tanpa_listing_sama_sekali_flash_jujur(): void
    {
        Http::fake();
        $m = $this->masterLengkap();

        $this->actingAs($this->admin())->post(route('marketplace-stock.master.konten', $m))
            ->assertRedirect()
            ->assertSessionHas('status', 'Konten & foto "Serum X" belum dikirim — belum ada listing marketplace tertaut (tautkan lewat "Tambah ke Marketplace")')
            ->assertSessionMissing('error');

        Http::assertNothingSent();
    }

    public function test_dorong_konten_master_kosong_semua_dilewati_flash_jujur(): void
    {
        $this->tiktokConn();
        Http::fake();
        $m = $this->masterKosong();
        $l = $this->tiktokListing($m);
        // Produk bervarian (ada SKU lain di item yang sama) → nama tak dikirim; selain nama semua kosong.
        MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'K-2', 'item_id' => $l->item_id, 'variation_id' => 'SKU2']);

        $this->actingAs($this->admin())->post(route('marketplace-stock.master.konten', $m))
            ->assertRedirect()
            ->assertSessionHas('status', 'Konten & foto "Kosong" tak dikirim — nama/deskripsi/berat/dimensi/barcode kosong (atau produk bervarian) dan foto belum ada (1 dilewati)')
            ->assertSessionMissing('error');

        Http::assertNothingSent();
        $this->assertNull($l->fresh()->last_content_status); // tak ada jejak: memang tak ada yang dikirim
    }

    // ---- POST: izin & binding ----

    public function test_reseller_tanpa_izin_ditolak_403_dan_tak_ada_yang_terkirim(): void
    {
        $this->tiktokConn();
        Http::fake();
        $m = $this->masterLengkap();
        $l = $this->tiktokListing($m);

        $this->actingAs($this->reseller())->post(route('marketplace-stock.master.konten', $m))->assertForbidden();

        Http::assertNothingSent();
        $this->assertNull($l->fresh()->last_content_status);
    }

    public function test_tamu_diarahkan_ke_login(): void
    {
        Http::fake();
        $m = $this->masterLengkap();

        $this->post(route('marketplace-stock.master.konten', $m))->assertRedirect(route('login'));

        Http::assertNothingSent();
    }

    public function test_master_tak_ada_404(): void
    {
        $this->actingAs($this->admin())->post(route('marketplace-stock.master.konten', 999999))->assertNotFound();
    }

    // ---- UI: halaman Ubah ----

    public function test_halaman_ubah_memuat_form_dorong_konten_sendiri_tanpa_form_bersarang(): void
    {
        Storage::fake('public');
        $m = MarketplaceMaster::create(['master_sku' => 'N-1', 'name' => 'N']);
        // 2 foto -> ada form Hapus/Jadikan Utama per foto: kondisi terpadat utk uji <form> bersarang.
        app(ImageService::class)->attach($m, UploadedFile::fake()->image('a.jpg'), MarketplaceMaster::MASTER_IMAGE);
        app(ImageService::class)->attach($m, UploadedFile::fake()->image('b.jpg'), MarketplaceMaster::MASTER_IMAGE);
        $aksi = route('marketplace-stock.master.konten', $m);

        $html = $this->actingAs($this->admin())->get(route('marketplace-stock.edit', $m))
            ->assertOk()
            ->assertSee('Dorong Konten & Foto ke Marketplace')
            ->assertSee($aksi, false)
            ->assertSee(self::KONFIRMASI, false)
            ->getContent();

        // Form konten = <form> TERSENDIRI (bukan di dalam form Simpan): POST + @csrf, tak memuat <form> lain.
        $this->assertSame(1, preg_match('#<form\b[^>]*method="POST"[^>]*action="'.preg_quote($aksi, '#').'"[^>]*>(.*?)</form>#s', $html, $mm), 'Form dorong konten (POST) tak ditemukan.');
        $this->assertStringContainsString('name="_token"', $mm[1]);
        $this->assertStringNotContainsString('<form', $mm[1]);

        // Kedalaman <form> di <main> maks 1 (HTML larang <form> bersarang) & seimbang.
        [$akhir, $maks] = $this->kedalamanForm($html);
        $this->assertSame(0, $akhir, '<form> tak seimbang (ada yang tak tertutup).');
        $this->assertSame(1, $maks, 'Ada <form> bersarang di halaman Ubah Produk Master.');
    }

    public function test_halaman_tambah_tak_menampilkan_tombol_dorong_konten(): void
    {
        // Master baru belum tersimpan -> belum punya listing: tak ada yang bisa didorong.
        $this->actingAs($this->admin())->get(route('marketplace-stock.create'))
            ->assertOk()
            ->assertDontSee('Dorong Konten');
    }

    // ---- UI: katalog (menu Atur) ----

    public function test_katalog_menu_atur_memuat_aksi_dorong_konten_per_master(): void
    {
        $a = MarketplaceMaster::create(['master_sku' => 'A-1', 'name' => 'Produk A']);
        $b = MarketplaceMaster::create(['master_sku' => 'B-1', 'name' => 'Produk B']);

        $html = $this->actingAs($this->admin())->get(route('marketplace-stock.index'))
            ->assertOk()
            ->assertSee('Dorong konten & foto')
            ->assertSee(route('marketplace-stock.master.konten', $a), false)
            ->assertSee(route('marketplace-stock.master.konten', $b), false)
            ->assertSee(self::KONFIRMASI, false)
            ->getContent();

        [$akhir, $maks] = $this->kedalamanForm($html);
        $this->assertSame(0, $akhir, '<form> tak seimbang di katalog.');
        $this->assertSame(1, $maks, 'Ada <form> bersarang di katalog Produk Master.');
    }

    // ---- Regresi: jalur stok/harga TAK berubah ----

    public function test_teks_gagal_stok_harga_tetap_seperti_semula(): void
    {
        // Master ber-stok + listing ber-item_id TANPA koneksi TikTok -> push stok gagal (harga kosong -> dilewati).
        // pushFlash dgn hint default HARUS tetap memberi teks lama persis (pemanggil lama tak boleh berubah).
        $m = MarketplaceMaster::create(['master_sku' => 'FM-1', 'name' => 'Hana', 'name_key' => 'hana']);
        MarketplaceListing::create(['channel' => 'tiktok', 'seller_sku' => 'FM-1', 'item_id' => 'P1', 'variation_id' => 'V1', 'warehouse_id' => 'W1', 'master_id' => $m->id]);

        $this->actingAs($this->admin())->post(route('marketplace-stock.master.stok', $m), ['quantity' => 10])
            ->assertRedirect()
            ->assertSessionHas('status', 'Stok master "Hana" disetel. (1 dilewati, 1 GAGAL)')
            ->assertSessionHas('error', '1 push ke marketplace GAGAL — stok/harga belum masuk. Buka Stok TikTok / Stok Shopee untuk lihat pesan error tiap listing (sering: scope Product app belum di-otorisasi ulang).');
    }
}
