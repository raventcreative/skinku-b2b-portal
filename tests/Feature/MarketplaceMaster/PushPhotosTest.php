<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\File;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\ShopeeConnection;
use App\Models\TiktokConnection;
use App\Models\User;
use App\Services\ImageService;
use App\Services\MarketplaceMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Mesin dorong FOTO master ke listing marketplace yang SUDAH ADA (Fase 2, digabung ke "Dorong Konten"):
 * photoHash (kunci diff-guard), pushPhotos (upload semua foto urut lalu GANTI SELURUH set foto listing),
 * dan gabungan konten+foto di pushMasterContent (1 listing = 1 unit).
 *
 * Pengaman yang dikunci di sini: master tanpa foto TAK mengosongkan listing (skip tanpa HTTP); foto hanya
 * di-upload & diganti bila set foto master berubah (walau tombol memaksa konten); satu upload gagal = set TAK
 * dikirim (tak ada ganti-parsial); jalur stok/harga/cron tak pernah mengirim foto; HQ tak tersentuh.
 */
class PushPhotosTest extends TestCase
{
    use RefreshDatabase;

    // URL bertanda-tangan punya query string → pola fake WAJIB diakhiri `*`.
    private const TT_UPLOAD = '*/product/202309/images/upload*';

    private const TT_EDIT = '*/product/202309/products/*/partial_edit*';

    private const SP_UPLOAD = '*/api/v2/media_space/upload_image*';

    private const SP_UPDATE = '*/api/v2/product/update_item*';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    // ---- helper ----

    private function svc(): MarketplaceMasterService
    {
        return app(MarketplaceMasterService::class);
    }

    private function admin(): User
    {
        return User::create(['name' => 'a', 'fullname' => 'A', 'username' => 'a'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE]);
    }

    private function tiktokConn(): void
    {
        TiktokConnection::create(['shop_id' => 's', 'shop_cipher' => 'c', 'access_token' => 't', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);
    }

    private function shopeeConn(): void
    {
        ShopeeConnection::create(['shop_id' => '123', 'access_token' => 't', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);
    }

    /** Master TANPA konten (deskripsi/berat/dimensi kosong) → pushContent skip tanpa HTTP; yang diuji murni foto. */
    private function master(array $over = []): MarketplaceMaster
    {
        return MarketplaceMaster::create(array_merge(['master_sku' => 'SX-1', 'name' => ''], $over));
    }

    /** Foto master lewat jalur upload asli (ImageService); ukuran beda-beda supaya byte tiap foto unik. */
    private function tambahFoto(MarketplaceMaster $m, string $nama = 'c.jpg', int $sisi = 30): File
    {
        return app(ImageService::class)->attach($m, UploadedFile::fake()->image($nama, $sisi, $sisi), MarketplaceMaster::MASTER_IMAGE);
    }

    private function masterBerfoto(int $jumlah = 2, array $over = []): MarketplaceMaster
    {
        $m = $this->master($over);
        for ($i = 1; $i <= $jumlah; $i++) {
            $this->tambahFoto($m, "foto{$i}.jpg", 10 + $i);
        }

        return $m;
    }

    /** @return list<File> foto master urut (pertama = cover) */
    private function foto(MarketplaceMaster $m): array
    {
        return $m->filesIn(MarketplaceMaster::MASTER_IMAGE)->get()->all();
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

    /** Nama file bagian multipart di request upload. */
    private function namaFile(Request $req): string
    {
        return preg_match('/filename="([^"]+)"/', $req->body(), $mm) === 1 ? $mm[1] : '?';
    }

    /** Body JSON request (set foto / konten); [] utk body multipart. */
    private function bodyJson(Request $req): array
    {
        return json_decode($req->body(), true) ?? [];
    }

    /**
     * Fake 4 endpoint dorong konten+foto; request lain (mis. stok/harga) = stray → gagal keras. Upload menjawab ref
     * yang diturunkan dari NAMA FILE terkirim (TikTok `tos://foto-{id}.jpg`, Shopee `sp-foto-{id}.jpg`), jadi urutan
     * main_images / image_id_list bisa dicocokkan persis dgn urutan foto master. $override menimpa per pola.
     */
    private function fakeMarketplace(array $override = []): void
    {
        Http::preventStrayRequests();
        Http::fake(array_merge([
            self::TT_UPLOAD => fn (Request $r) => Http::response(['code' => 0, 'message' => 'ok', 'data' => ['uri' => 'tos://'.$this->namaFile($r)]]),
            self::TT_EDIT => Http::response(['code' => 0, 'message' => 'Success', 'data' => []]),
            self::SP_UPLOAD => fn (Request $r) => Http::response(['error' => '', 'message' => '', 'response' => ['image_info' => ['image_id' => 'sp-'.$this->namaFile($r)]]]),
            self::SP_UPDATE => Http::response(['error' => '', 'message' => '', 'response' => []]),
        ], $override));
    }

    /** Jejak HTTP BERURUTAN diringkas per jenis panggilan (set foto vs konten dibedakan dari isi body JSON). */
    private function jejak(): array
    {
        return Http::recorded()->map(function (array $pair) {
            $req = $pair[0];
            $url = $req->url();

            return match (true) {
                str_contains($url, '/product/202309/images/upload') => 'tt-upload',
                str_contains($url, '/partial_edit') => array_key_exists('main_images', $this->bodyJson($req)) ? 'tt-set-foto' : 'tt-konten',
                str_contains($url, '/api/v2/media_space/upload_image') => 'sp-upload',
                str_contains($url, '/api/v2/product/update_item') => array_key_exists('image', $this->bodyJson($req)) ? 'sp-set-foto' : 'sp-konten',
                default => $url,
            };
        })->values()->all();
    }

    /** Body main_images TikTok yang diharapkan utk foto-foto ini (urut). */
    private function mainImages(File ...$files): array
    {
        return ['main_images' => array_map(fn (File $f) => ['uri' => "tos://foto-{$f->id}.jpg"], $files)];
    }

    // ---- photoHash ----

    public function test_photo_hash_rumus_dan_stabil(): void
    {
        $m = $this->masterBerfoto(2);
        [$a, $b] = $this->foto($m);

        $hash = $this->svc()->photoHash($m);

        $this->assertSame(md5(json_encode([[$a->id, 0], [$b->id, 1]])), $hash);
        $this->assertSame($hash, $this->svc()->photoHash($m));          // dipanggil ulang
        $this->assertSame($hash, $this->svc()->photoHash($m->fresh())); // model dimuat ulang dari DB
    }

    public function test_photo_hash_berubah_bila_foto_utama_diganti(): void
    {
        $m = $this->masterBerfoto(2);
        [, $kedua] = $this->foto($m);
        $sebelum = $this->svc()->photoHash($m);

        $this->actingAs($this->admin())->post(route('marketplace-stock.master.foto.utama', [$m, $kedua]))->assertRedirect();

        $this->assertSame($kedua->id, $this->foto($m)[0]->id); // sanity: foto kedua kini cover
        $this->assertNotSame($sebelum, $this->svc()->photoHash($m));
    }

    public function test_photo_hash_berubah_bila_foto_ditambah_atau_dihapus(): void
    {
        $m = $this->masterBerfoto(2);
        [$pertama] = $this->foto($m);
        $dua = $this->svc()->photoHash($m);

        $this->tambahFoto($m);
        $tiga = $this->svc()->photoHash($m);
        $this->assertNotSame($dua, $tiga);

        $pertama->delete();
        $setelahHapus = $this->svc()->photoHash($m);
        $this->assertNotSame($tiga, $setelahHapus);
        $this->assertNotSame($dua, $setelahHapus);
    }

    public function test_photo_hash_kosong_bila_master_tanpa_foto_dan_file_koleksi_lain_tak_dihitung(): void
    {
        $m = $this->master();
        $this->assertSame('', $this->svc()->photoHash($m));

        app(ImageService::class)->attach($m, UploadedFile::fake()->image('x.jpg'), 'koleksi_lain');
        $this->assertSame('', $this->svc()->photoHash($m));
    }

    // ---- pushPhotos: skip ----

    public function test_master_tanpa_foto_skip_tanpa_http_walau_force_dan_tanpa_koneksi(): void
    {
        // Pengaman #1: master tanpa foto TAK boleh mengosongkan set foto listing. Skip terjadi SEBELUM cek
        // koneksi/token (tak ada koneksi di sini → kalau urutannya salah, hasilnya 'failed', bukan 'skip').
        Http::fake();
        $m = $this->master();
        $tiktok = $this->tiktokListing($m);
        $shopee = $this->shopeeListing($m);

        $this->assertSame('skip', $this->svc()->pushPhotos($tiktok, $m, true));
        $this->assertSame('skip', $this->svc()->pushPhotos($shopee, $m, true));

        Http::assertNothingSent();
        foreach ([$tiktok, $shopee] as $l) {
            $l->refresh();
            $this->assertNull($l->last_photo_status);
            $this->assertNull($l->last_photo_error);
            $this->assertNull($l->last_photo_pushed_at);
            $this->assertNull($l->photo_hash);
        }
    }

    // ---- pushPhotos: TikTok ----

    public function test_tiktok_upload_semua_foto_urut_lalu_satu_partial_edit_main_images(): void
    {
        $this->tiktokConn();
        $this->fakeMarketplace();
        $m = $this->masterBerfoto(2);
        [$a, $b] = $this->foto($m);
        $l = $this->tiktokListing($m);

        $r = $this->svc()->pushPhotos($l, $m, false);

        $this->assertSame('ok', $r);
        // Semua foto di-upload DULU (urut), baru 1 set — tak ada set sebelum semua foto ter-upload.
        $this->assertSame(['tt-upload', 'tt-upload', 'tt-set-foto'], $this->jejak());
        foreach ([$a, $b] as $f) {
            $bytes = Storage::disk('public')->get($f->path);
            Http::assertSent(fn (Request $req) => str_contains($req->url(), '/product/202309/images/upload')
                && str_contains($req->url(), 'shop_cipher=c')
                && $req->hasHeader('x-tts-access-token', 't')
                && $req->isMultipart()
                && str_contains($req->body(), "name=\"data\"; filename=\"foto-{$f->id}.jpg\"")
                && str_contains($req->body(), $bytes)                // byte ASLI foto tersimpan
                && str_contains($req->body(), 'MAIN_IMAGE'));
        }
        // main_images = uri hasil upload dgn URUTAN foto master (pertama = cover) → ganti seluruh set.
        Http::assertSent(fn (Request $req) => $req->method() === 'POST'
            && str_contains($req->url(), '/product/202309/products/PID1/partial_edit')
            && str_contains($req->url(), 'shop_cipher=c')
            && $req->hasHeader('x-tts-access-token', 't')
            && $this->bodyJson($req) === $this->mainImages($a, $b));

        $l->refresh();
        $this->assertSame('ok', $l->last_photo_status);
        $this->assertNull($l->last_photo_error);
        $this->assertNotNull($l->last_photo_pushed_at);
        $this->assertSame($this->svc()->photoHash($m), $l->photo_hash);
    }

    public function test_urutan_main_images_mengikuti_foto_utama_master(): void
    {
        $this->tiktokConn();
        $this->fakeMarketplace();
        $m = $this->masterBerfoto(2);
        [$a, $b] = $this->foto($m);
        $this->actingAs($this->admin())->post(route('marketplace-stock.master.foto.utama', [$m, $b]))->assertRedirect();
        $l = $this->tiktokListing($m);

        $this->assertSame('ok', $this->svc()->pushPhotos($l, $m, false));

        Http::assertSent(fn (Request $req) => str_contains($req->url(), '/partial_edit')
            && $this->bodyJson($req) === $this->mainImages($b, $a));
    }

    public function test_nama_file_upload_aman_dan_ekstensi_asli_dipertahankan(): void
    {
        // original_name bisa berisi tanda kutip → merusak header multipart (Guzzle tak meng-escape filename).
        // Nama dibuat sendiri; ekstensi ikut file tersimpan (Content-Type bagian multipart diturunkan darinya).
        $this->tiktokConn();
        $this->fakeMarketplace();
        $m = $this->master();
        $jpg = $this->tambahFoto($m, 'foto "keren".jpg');
        Storage::disk('public')->put('master_image/asli.png', 'PNGBYTES');
        $png = File::create([
            'fileable_type' => MarketplaceMaster::class, 'fileable_id' => $m->id, 'collection' => MarketplaceMaster::MASTER_IMAGE,
            'disk' => 'public', 'path' => 'master_image/asli.png', 'original_name' => 'x".png', 'mime_type' => 'image/png', 'sort_order' => 1,
        ]);
        $l = $this->tiktokListing($m);

        $this->assertSame('ok', $this->svc()->pushPhotos($l, $m, false));

        Http::assertSent(fn (Request $req) => str_contains($req->body(), "filename=\"foto-{$jpg->id}.jpg\""));
        Http::assertSent(fn (Request $req) => str_contains($req->body(), "filename=\"foto-{$png->id}.png\"")
            && str_contains($req->body(), 'Content-Type: image/png')
            && str_contains($req->body(), 'PNGBYTES'));
        Http::assertNotSent(fn (Request $req) => str_contains($req->body(), 'keren') || str_contains($req->body(), 'x".png'));
    }

    public function test_diff_guard_set_foto_sama_skip_tanpa_http(): void
    {
        $this->tiktokConn();
        $this->fakeMarketplace();
        $m = $this->masterBerfoto(2);
        $l = $this->tiktokListing($m);
        $svc = $this->svc();

        $this->assertSame('ok', $svc->pushPhotos($l, $m, false));
        Http::assertSentCount(3);

        // Model dimuat ulang dari DB → hash memang tersimpan. Pengaman #2: foto tak di-upload/diganti ulang.
        $this->assertSame('skip', $svc->pushPhotos($l->fresh(), $m, false));
        Http::assertSentCount(3);
    }

    public function test_force_true_upload_dan_ganti_ulang_walau_set_foto_sama(): void
    {
        $this->tiktokConn();
        $this->fakeMarketplace();
        $m = $this->masterBerfoto(2);
        $l = $this->tiktokListing($m);
        $svc = $this->svc();

        $this->assertSame('ok', $svc->pushPhotos($l, $m, false));
        $this->assertSame('ok', $svc->pushPhotos($l->fresh(), $m, true));

        $this->assertSame(['tt-upload', 'tt-upload', 'tt-set-foto', 'tt-upload', 'tt-upload', 'tt-set-foto'], $this->jejak());
    }

    public function test_set_foto_berubah_lolos_diff_guard_dan_hash_diperbarui(): void
    {
        $this->tiktokConn();
        $this->fakeMarketplace();
        $m = $this->masterBerfoto(2);
        $l = $this->tiktokListing($m);
        $svc = $this->svc();

        $this->assertSame('ok', $svc->pushPhotos($l, $m, false));
        $hashLama = $l->fresh()->photo_hash;

        $this->tambahFoto($m);
        $this->assertSame('ok', $svc->pushPhotos($l->fresh(), $m, false)); // tanpa force, tapi set fotonya beda

        [$a, $b, $c] = $this->foto($m);
        Http::assertSentCount(7); // (2 upload + set) + (3 upload + set)
        Http::assertSent(fn (Request $req) => str_contains($req->url(), '/partial_edit') && $this->bodyJson($req) === $this->mainImages($a, $b, $c));
        $baru = $l->fresh()->photo_hash;
        $this->assertNotSame($hashLama, $baru);
        $this->assertSame($svc->photoHash($m), $baru);
    }

    public function test_upload_ditolak_failed_pesan_asli_tanpa_set_dan_hash_tak_tersimpan(): void
    {
        $this->tiktokConn();
        $this->fakeMarketplace([self::TT_UPLOAD => Http::response(['code' => 12052217, 'message' => 'Image size is too small'])]);
        $m = $this->masterBerfoto(2);
        $l = $this->tiktokListing($m);

        $this->assertSame('failed', $this->svc()->pushPhotos($l, $m, false));

        $this->assertSame(['tt-upload'], $this->jejak()); // berhenti di foto pertama; set foto TAK dipanggil
        $l->refresh();
        $this->assertSame('failed', $l->last_photo_status);
        $this->assertStringContainsString('Image size is too small', (string) $l->last_photo_error);
        $this->assertNull($l->photo_hash);            // gagal ≠ terpasang → diff-guard tak boleh "mengunci"
        $this->assertNull($l->last_photo_pushed_at);
    }

    public function test_foto_kedua_gagal_upload_tak_ada_ganti_parsial(): void
    {
        // Ganti-semua dgn set setengah jadi = listing kehilangan foto. Satu foto gagal → set TAK dikirim sama sekali.
        $this->tiktokConn();
        $ke = 0;
        $this->fakeMarketplace([self::TT_UPLOAD => function (Request $r) use (&$ke) {
            return ++$ke === 2
                ? Http::response(['code' => 12052217, 'message' => 'Image ratio invalid'])
                : Http::response(['code' => 0, 'data' => ['uri' => 'tos://'.$this->namaFile($r)]]);
        }]);
        $m = $this->masterBerfoto(3);
        $l = $this->tiktokListing($m);

        $this->assertSame('failed', $this->svc()->pushPhotos($l, $m, false));

        $this->assertSame(['tt-upload', 'tt-upload'], $this->jejak());
        $this->assertStringContainsString('Image ratio invalid', (string) $l->refresh()->last_photo_error);
        $this->assertNull($l->photo_hash);
    }

    public function test_set_main_images_ditolak_tiktok_tercatat_failed_dengan_pesan_asli(): void
    {
        // GERBANG spec §3: bila TikTok menolak main_images via partial_edit, JANGAN diam — status failed + pesan
        // asli TikTok (tampil di halaman Stok TikTok); hash tak tersimpan → klik berikutnya mencoba lagi.
        $this->tiktokConn();
        $this->fakeMarketplace([self::TT_EDIT => Http::response(['code' => 12019004, 'message' => 'main_images is not allowed to be edited'])]);
        $m = $this->masterBerfoto(2);
        $l = $this->tiktokListing($m);

        $this->assertSame('failed', $this->svc()->pushPhotos($l, $m, false));

        $this->assertSame(['tt-upload', 'tt-upload', 'tt-set-foto'], $this->jejak());
        $l->refresh();
        $this->assertSame('failed', $l->last_photo_status);
        $this->assertStringContainsString('main_images is not allowed to be edited', (string) $l->last_photo_error);
        $this->assertNull($l->photo_hash);
    }

    public function test_setelah_gagal_klik_berikutnya_mencoba_lagi_dan_error_dibersihkan(): void
    {
        $this->tiktokConn();
        $gagal = true;
        $this->fakeMarketplace([self::TT_UPLOAD => function (Request $r) use (&$gagal) {
            return $gagal
                ? Http::response(['code' => 12052217, 'message' => 'Image size is too small'])
                : Http::response(['code' => 0, 'data' => ['uri' => 'tos://'.$this->namaFile($r)]]);
        }]);
        $m = $this->masterBerfoto(1);
        $l = $this->tiktokListing($m);
        $svc = $this->svc();

        $this->assertSame('failed', $svc->pushPhotos($l, $m, false));
        $gagal = false;
        $this->assertSame('ok', $svc->pushPhotos($l->fresh(), $m, false)); // hash tak tersimpan saat gagal → bukan 'skip'

        $l->refresh();
        $this->assertSame('ok', $l->last_photo_status);
        $this->assertNull($l->last_photo_error);
        $this->assertSame($svc->photoHash($m), $l->photo_hash);
        $this->assertNotNull($l->last_photo_pushed_at);
    }

    public function test_gagal_setelah_sukses_mempertahankan_hash_dan_waktu_sukses_terakhir(): void
    {
        $this->tiktokConn();
        $gagal = false;
        $this->fakeMarketplace([self::TT_UPLOAD => function (Request $r) use (&$gagal) {
            return $gagal
                ? Http::response(['code' => 12052217, 'message' => 'Image size is too small'])
                : Http::response(['code' => 0, 'data' => ['uri' => 'tos://'.$this->namaFile($r)]]);
        }]);
        $m = $this->masterBerfoto(1);
        $l = $this->tiktokListing($m);
        $svc = $this->svc();

        $this->assertSame('ok', $svc->pushPhotos($l, $m, false));
        $sukses = $l->fresh();

        $this->tambahFoto($m);
        $gagal = true;
        $this->assertSame('failed', $svc->pushPhotos($l->fresh(), $m, false));

        $l->refresh();
        $this->assertSame('failed', $l->last_photo_status);
        $this->assertNotNull($l->last_photo_error);
        $this->assertSame($sukses->photo_hash, $l->photo_hash);                     // yang terpasang di marketplace tetap set lama
        $this->assertSame($sukses->last_photo_pushed_at, $l->last_photo_pushed_at); // waktu = push SUKSES terakhir
    }

    public function test_timeout_koneksi_saat_upload_tercatat_failed(): void
    {
        $this->tiktokConn();
        $this->fakeMarketplace([self::TT_UPLOAD => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);
        $m = $this->masterBerfoto(1);
        $l = $this->tiktokListing($m);

        $this->assertSame('failed', $this->svc()->pushPhotos($l, $m, true));

        $l->refresh();
        $this->assertSame('failed', $l->last_photo_status);
        $this->assertStringContainsString('timed out', (string) $l->last_photo_error);
    }

    public function test_pesan_error_dipotong_500_karakter(): void
    {
        $this->tiktokConn();
        $this->fakeMarketplace([self::TT_UPLOAD => Http::response(['code' => 12052217, 'message' => str_repeat('x', 800)])]);
        $m = $this->masterBerfoto(1);
        $l = $this->tiktokListing($m);

        $this->svc()->pushPhotos($l, $m, true);

        $this->assertSame(500, mb_strlen((string) $l->refresh()->last_photo_error));
    }

    public function test_tanpa_koneksi_failed_tanpa_http(): void
    {
        Http::fake(); // sengaja TANPA baris TiktokConnection / ShopeeConnection
        $m = $this->masterBerfoto(1);
        $tiktok = $this->tiktokListing($m);
        $shopee = $this->shopeeListing($m);

        $this->assertSame('failed', $this->svc()->pushPhotos($tiktok, $m, true));
        $this->assertSame('failed', $this->svc()->pushPhotos($shopee, $m, true));

        $this->assertSame('TikTok belum terhubung', $tiktok->refresh()->last_photo_error);
        $this->assertSame('Shopee belum terhubung', $shopee->refresh()->last_photo_error);
        Http::assertNothingSent();
    }

    public function test_file_foto_hilang_dari_server_failed_dengan_pesan_jelas_tanpa_upload(): void
    {
        $this->tiktokConn();
        $this->fakeMarketplace();
        $m = $this->masterBerfoto(2);
        [$pertama] = $this->foto($m);
        Storage::disk('public')->delete($pertama->path);
        $l = $this->tiktokListing($m);

        $this->assertSame('failed', $this->svc()->pushPhotos($l, $m, false));

        Http::assertNothingSent();
        $error = (string) $l->refresh()->last_photo_error;
        $this->assertStringContainsString("#{$pertama->id}", $error);
        $this->assertStringContainsString('tak ditemukan', $error);
        $this->assertNull($l->photo_hash);
    }

    // ---- pushPhotos: Shopee ----

    public function test_shopee_upload_semua_foto_lalu_update_item_image_id_list(): void
    {
        $this->shopeeConn();
        $this->fakeMarketplace();
        $m = $this->masterBerfoto(2);
        [$a, $b] = $this->foto($m);
        $l = $this->shopeeListing($m);

        $this->assertSame('ok', $this->svc()->pushPhotos($l, $m, false));

        $this->assertSame(['sp-upload', 'sp-upload', 'sp-set-foto'], $this->jejak());
        foreach ([$a, $b] as $f) {
            $bytes = Storage::disk('public')->get($f->path);
            Http::assertSent(fn (Request $req) => str_contains($req->url(), '/api/v2/media_space/upload_image')
                && str_contains($req->url(), 'shop_id=123')
                && str_contains($req->url(), 'access_token=t')
                && $req->isMultipart()
                && str_contains($req->body(), "name=\"image\"; filename=\"foto-{$f->id}.jpg\"")
                && str_contains($req->body(), $bytes));
        }
        // item_id (int, dari listing) + image.image_id_list urut — ganti SELURUH set foto item, tak ada key lain.
        Http::assertSent(fn (Request $req) => $req->method() === 'POST'
            && str_contains($req->url(), '/api/v2/product/update_item')
            && str_contains($req->url(), 'shop_id=123')
            && $this->bodyJson($req) === ['item_id' => 555, 'image' => ['image_id_list' => ["sp-foto-{$a->id}.jpg", "sp-foto-{$b->id}.jpg"]]]);

        $l->refresh();
        $this->assertSame('ok', $l->last_photo_status);
        $this->assertNull($l->last_photo_error);
        $this->assertNotNull($l->last_photo_pushed_at);
        $this->assertSame($this->svc()->photoHash($m), $l->photo_hash);
    }

    public function test_shopee_update_item_ditolak_tercatat_failed_dengan_pesan_asli(): void
    {
        $this->shopeeConn();
        $this->fakeMarketplace([self::SP_UPDATE => Http::response(['error' => 'error_param', 'message' => 'image_id_list invalid', 'response' => []])]);
        $m = $this->masterBerfoto(1);
        $l = $this->shopeeListing($m);

        $this->assertSame('failed', $this->svc()->pushPhotos($l, $m, false));

        $l->refresh();
        $this->assertSame('failed', $l->last_photo_status);
        $this->assertStringContainsString('image_id_list invalid', (string) $l->last_photo_error);
        $this->assertNull($l->photo_hash);
    }

    // ---- Cache upload dalam SATU run (anti-timeout) ----

    public function test_dua_listing_tiktok_satu_run_foto_diupload_sekali_set_per_listing(): void
    {
        // Upload sekuensial dalam 1 request web rawan timeout → foto di-upload SEKALI per channel per klik,
        // uri-nya dipakai ulang listing berikutnya (yang tetap di-set sendiri-sendiri).
        $this->tiktokConn();
        $this->fakeMarketplace();
        $m = $this->masterBerfoto(2); // tanpa konten → konten skip, yang dihitung murni foto
        [$a, $b] = $this->foto($m);
        $l1 = $this->tiktokListing($m);
        $l2 = $this->tiktokListing($m, ['seller_sku' => 'SX-1-B', 'item_id' => 'PID2', 'variation_id' => 'SKU2']);

        $r = $this->svc()->pushMasterContent($m);

        $this->assertSame(['pushed' => 2, 'skipped' => 0, 'failed' => 0], $r); // konten skip + foto ok → pushed
        $this->assertSame(['tt-upload', 'tt-upload', 'tt-set-foto', 'tt-set-foto'], $this->jejak());
        foreach (['PID1', 'PID2'] as $pid) {
            Http::assertSent(fn (Request $req) => str_contains($req->url(), "/products/{$pid}/partial_edit")
                && $this->bodyJson($req) === $this->mainImages($a, $b));
        }
        foreach ([$l1, $l2] as $l) {
            $this->assertSame('ok', $l->fresh()->last_photo_status);
            $this->assertSame($this->svc()->photoHash($m), $l->fresh()->photo_hash);
        }
    }

    public function test_cache_per_channel_ref_tiktok_tak_pernah_dikirim_ke_shopee(): void
    {
        $this->tiktokConn();
        $this->shopeeConn();
        $this->fakeMarketplace();
        $m = $this->masterBerfoto(2);
        [$a, $b] = $this->foto($m);
        $this->tiktokListing($m);
        $this->shopeeListing($m);

        $this->assertSame(['pushed' => 2, 'skipped' => 0, 'failed' => 0], $this->svc()->pushMasterContent($m));

        // Tiap channel upload ke media-nya sendiri (uri TikTok tak berlaku di Shopee, dan sebaliknya).
        $jumlah = array_count_values($this->jejak());
        ksort($jumlah);
        $this->assertSame(['sp-set-foto' => 1, 'sp-upload' => 2, 'tt-set-foto' => 1, 'tt-upload' => 2], $jumlah);
        Http::assertSent(fn (Request $req) => str_contains($req->url(), '/partial_edit') && $this->bodyJson($req) === $this->mainImages($a, $b));
        Http::assertSent(fn (Request $req) => str_contains($req->url(), '/api/v2/product/update_item')
            && $this->bodyJson($req) === ['item_id' => 555, 'image' => ['image_id_list' => ["sp-foto-{$a->id}.jpg", "sp-foto-{$b->id}.jpg"]]]);
    }

    public function test_upload_gagal_tak_meninggalkan_cache_parsial(): void
    {
        // Listing pertama gagal di foto ke-2 → listing berikutnya upload ULANG semua foto, bukan pakai hasil setengah jadi.
        $this->tiktokConn();
        $ke = 0;
        $this->fakeMarketplace([self::TT_UPLOAD => function (Request $r) use (&$ke) {
            return ++$ke === 2
                ? Http::response(['code' => 12052217, 'message' => 'Image ratio invalid'])
                : Http::response(['code' => 0, 'data' => ['uri' => 'tos://'.$this->namaFile($r)]]);
        }]);
        $m = $this->masterBerfoto(2);
        [$a, $b] = $this->foto($m);
        $l1 = $this->tiktokListing($m);
        $l2 = $this->tiktokListing($m, ['seller_sku' => 'SX-1-B', 'item_id' => 'PID2', 'variation_id' => 'SKU2']);

        $r = $this->svc()->pushMasterContent($m);

        $this->assertSame(['pushed' => 1, 'skipped' => 0, 'failed' => 1], $r);
        // listing ke-1: upload ok, upload GAGAL (tanpa set) · listing ke-2: upload ulang 2 foto, lalu set.
        $this->assertSame(['tt-upload', 'tt-upload', 'tt-upload', 'tt-upload', 'tt-set-foto'], $this->jejak());
        $listings = collect([$l1->fresh(), $l2->fresh()]);
        $ok = $listings->firstWhere('last_photo_status', 'ok');
        $gagal = $listings->firstWhere('last_photo_status', 'failed');
        $this->assertNotNull($ok);
        $this->assertNotNull($gagal);
        Http::assertSent(fn (Request $req) => str_contains($req->url(), "/products/{$ok->item_id}/partial_edit")
            && $this->bodyJson($req) === $this->mainImages($a, $b));
        Http::assertNotSent(fn (Request $req) => str_contains($req->url(), "/products/{$gagal->item_id}/partial_edit"));
    }

    public function test_cache_terikat_set_foto_master_lain_tak_mendapat_uri_master_pertama(): void
    {
        // Cache dikunci channel + hash set foto: dipakai ulang lintas master pun, master lain tak mungkin mendapat
        // uri foto master pertama (salah foto di marketplace = kerusakan yang langsung terlihat pembeli).
        $this->tiktokConn();
        $this->fakeMarketplace();
        $m1 = $this->masterBerfoto(1);
        $m2 = $this->masterBerfoto(1, ['master_sku' => 'SY-1', 'name' => 'Serum Y']);
        $l1 = $this->tiktokListing($m1);
        $l2 = $this->tiktokListing($m2, ['seller_sku' => 'SY-1', 'item_id' => 'PID2', 'variation_id' => 'SKU2']);
        $uploaded = [];

        $this->assertSame('ok', $this->svc()->pushPhotos($l1, $m1, false, $uploaded));
        $this->assertSame('ok', $this->svc()->pushPhotos($l2, $m2, false, $uploaded));

        $this->assertSame(['tt-upload', 'tt-set-foto', 'tt-upload', 'tt-set-foto'], $this->jejak());
        Http::assertSent(fn (Request $req) => str_contains($req->url(), '/products/PID2/partial_edit')
            && $this->bodyJson($req) === $this->mainImages(...$this->foto($m2)));
    }

    // ---- pushMasterContent: gabungan konten + foto (1 listing = 1 unit) ----

    public function test_gabungan_konten_ok_dan_foto_ok_dihitung_satu_pushed(): void
    {
        $this->tiktokConn();
        $this->fakeMarketplace();
        $m = $this->masterBerfoto(2, ['description' => 'Serum X']);
        $l = $this->tiktokListing($m);

        $this->assertSame(['pushed' => 1, 'skipped' => 0, 'failed' => 0], $this->svc()->pushMasterContent($m));

        // Konten & foto = dua panggilan terpisah (penolakan foto tak menggagalkan konten, dan sebaliknya).
        $this->assertSame(['tt-konten', 'tt-upload', 'tt-upload', 'tt-set-foto'], $this->jejak());
        $l->refresh();
        $this->assertSame('ok', $l->last_content_status);
        $this->assertSame('ok', $l->last_photo_status);
    }

    public function test_gabungan_konten_ok_foto_gagal_dihitung_failed(): void
    {
        $this->tiktokConn();
        $this->fakeMarketplace([self::TT_UPLOAD => Http::response(['code' => 12052217, 'message' => 'Image size is too small'])]);
        $m = $this->masterBerfoto(1, ['description' => 'Serum X']);
        $l = $this->tiktokListing($m);

        $this->assertSame(['pushed' => 0, 'skipped' => 0, 'failed' => 1], $this->svc()->pushMasterContent($m));

        $l->refresh();
        $this->assertSame('ok', $l->last_content_status);   // konten tetap masuk…
        $this->assertSame('failed', $l->last_photo_status); // …tapi foto gagal → listing dihitung GAGAL (flash jujur)
    }

    public function test_gabungan_konten_gagal_foto_ok_dihitung_failed(): void
    {
        $this->tiktokConn();
        // partial_edit menolak KONTEN (body deskripsi) tapi menerima FOTO (body main_images).
        $this->fakeMarketplace([self::TT_EDIT => fn (Request $r) => array_key_exists('main_images', $this->bodyJson($r))
            ? Http::response(['code' => 0, 'data' => []])
            : Http::response(['code' => 12019001, 'message' => 'description too long'])]);
        $m = $this->masterBerfoto(1, ['description' => 'Serum X']);
        $l = $this->tiktokListing($m);

        $this->assertSame(['pushed' => 0, 'skipped' => 0, 'failed' => 1], $this->svc()->pushMasterContent($m));

        $l->refresh();
        $this->assertSame('failed', $l->last_content_status);
        $this->assertSame('ok', $l->last_photo_status);
    }

    public function test_gabungan_master_tanpa_foto_konten_tetap_jalan_foto_skip(): void
    {
        $this->tiktokConn();
        $this->fakeMarketplace();
        $m = $this->master(['description' => 'Serum X']);
        $l = $this->tiktokListing($m);

        $this->assertSame(['pushed' => 1, 'skipped' => 0, 'failed' => 0], $this->svc()->pushMasterContent($m));

        $this->assertSame(['tt-konten'], $this->jejak());
        $l->refresh();
        $this->assertSame('ok', $l->last_content_status);
        $this->assertNull($l->last_photo_status);
        $this->assertNull($l->photo_hash);
    }

    public function test_gabungan_konten_kosong_dan_foto_tak_berubah_dihitung_skipped(): void
    {
        $this->tiktokConn();
        $this->fakeMarketplace();
        $m = $this->masterBerfoto(2);
        $this->tiktokListing($m, ['photo_hash' => $this->svc()->photoHash($m), 'last_photo_pushed_at' => now()]); // set foto ini sudah terpasang

        // Mode otomatis (Simpan) = diff-guard: konten kosong + foto tak berubah → skipped, tanpa HTTP.
        $this->assertSame(['pushed' => 0, 'skipped' => 1, 'failed' => 0], $this->svc()->pushMasterContent($m, false, true));

        Http::assertNothingSent();
    }

    public function test_manual_paksa_foto_otomatis_lewat_diff_guard(): void
    {
        // Tombol "Dorong" = pushMasterContent(force=true): konten & foto SELALU dikirim ulang (manual = semua terdorong).
        // Mode otomatis (Simpan): foto hanya bila set foto master berubah — Simpan berulang tak terus meng-upload.
        $this->tiktokConn();
        $this->fakeMarketplace();
        $m = $this->masterBerfoto(2, ['description' => 'Serum X']);
        $l = $this->tiktokListing($m);
        $svc = $this->svc();

        $this->assertSame(['pushed' => 1, 'skipped' => 0, 'failed' => 0], $svc->pushMasterContent($m, true));
        $this->assertSame(['pushed' => 1, 'skipped' => 0, 'failed' => 0], $svc->pushMasterContent($m, true)); // paksa: foto lagi
        $this->assertSame(['tt-konten', 'tt-upload', 'tt-upload', 'tt-set-foto', 'tt-konten', 'tt-upload', 'tt-upload', 'tt-set-foto'], $this->jejak());

        $this->assertSame(['pushed' => 0, 'skipped' => 1, 'failed' => 0], $svc->pushMasterContent($m, false, true)); // otomatis: tak berubah
        $this->assertCount(8, $this->jejak());

        // Set foto master berubah → otomatis meng-upload & mengganti lagi (konten tak berubah → tak dikirim).
        $this->tambahFoto($m);
        $svc->pushMasterContent($m, false, true);
        $this->assertSame(['tt-upload', 'tt-upload', 'tt-upload', 'tt-set-foto'], array_slice($this->jejak(), 8));
        $this->assertSame($svc->photoHash($m), $l->fresh()->photo_hash);
    }

    public function test_simpan_sinkron_otomatis_hanya_yang_berubah_setelah_dorong_manual(): void
    {
        // Kabel asli: tombol Dorong (manual) → lalu form Simpan (update) menyinkronkan otomatis yang berubah saja.
        $this->tiktokConn();
        $this->fakeMarketplace();
        $m = $this->masterBerfoto(2, ['name' => 'Serum X', 'description' => 'Serum X']);
        $l = $this->tiktokListing($m);
        $admin = $this->admin();
        $form = fn (array $over = []) => array_merge(['name' => 'Serum X', 'master_sku' => 'SX-1', 'description' => 'Serum X'], $over);

        // Sebelum pernah didorong manual: Simpan TIDAK mengirim konten/foto (dorong pertama menimpa → wajib disengaja).
        $this->actingAs($admin)->put(route('marketplace-stock.update', $m), $form())->assertRedirect()->assertSessionMissing('error');
        $this->assertSame([], $this->jejak());

        $this->actingAs($admin)->post(route('marketplace-stock.master.konten', $m))->assertRedirect()->assertSessionMissing('error');
        $this->assertSame(['tt-konten', 'tt-upload', 'tt-upload', 'tt-set-foto'], $this->jejak());

        // Simpan tanpa perubahan → tak ada HTTP konten/foto baru.
        $this->actingAs($admin)->put(route('marketplace-stock.update', $m), $form())->assertRedirect()->assertSessionMissing('error');
        $this->assertCount(4, $this->jejak());

        // Deskripsi berubah → hanya konten terkirim; foto tak berubah → tak di-upload ulang.
        $this->actingAs($admin)->put(route('marketplace-stock.update', $m), $form(['description' => 'Serum X baru']))
            ->assertSessionHas('status', fn (string $s) => str_contains($s, 'tersinkron ke 1 listing'));
        $this->assertSame(['tt-konten'], array_slice($this->jejak(), 4));
        $this->assertSame('ok', $l->fresh()->last_photo_status);
    }

    // ---- Isolasi: jalur stok/harga/cron & HQ ----

    public function test_jalur_stok_harga_termasuk_cron_tak_pernah_mengirim_foto(): void
    {
        $this->tiktokConn();
        $this->shopeeConn();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['code' => 0, 'data' => [], 'error' => '', 'response' => []])]);
        $m = $this->masterBerfoto(2, ['base_stock' => 30, 'base_price' => 39000]);
        $tiktok = $this->tiktokListing($m);
        $shopee = $this->shopeeListing($m);
        $svc = $this->svc();

        $svc->pushListing($tiktok, true);
        $svc->pushMaster($m);
        $svc->pushDirty();   // dipakai cron 5-menit
        $svc->pushAll();

        Http::assertSent(fn (Request $req) => str_contains($req->url(), 'inventory/update')); // stok/harga memang jalan…
        Http::assertNotSent(fn (Request $req) => str_contains($req->url(), 'images/upload')     // …tapi foto tidak.
            || str_contains($req->url(), 'media_space')
            || str_contains($req->url(), 'partial_edit')
            || str_contains($req->url(), '/product/update_item'));
        foreach ([$tiktok, $shopee] as $l) {
            $l->refresh();
            $this->assertNull($l->last_photo_status);
            $this->assertNull($l->last_photo_pushed_at);
            $this->assertNull($l->photo_hash);
        }
    }

    public function test_dorong_foto_tak_menyentuh_hq_maupun_stok_harga(): void
    {
        $this->tiktokConn();
        $this->shopeeConn();
        $this->fakeMarketplace();
        $m = $this->masterBerfoto(2, ['base_stock' => 30, 'base_price' => 39000]);
        $tiktok = $this->tiktokListing($m);
        $shopee = $this->shopeeListing($m);
        $hqSebelum = DB::table('stock_movements')->count();

        $this->assertSame(['pushed' => 2, 'skipped' => 0, 'failed' => 0], $this->svc()->pushMasterContent($m));

        $this->assertSame($hqSebelum, DB::table('stock_movements')->count());
        Http::assertNotSent(fn (Request $req) => str_contains($req->url(), 'inventory/update')
            || str_contains($req->url(), 'prices/update')
            || str_contains($req->url(), '/product/update_stock')
            || str_contains($req->url(), '/product/update_price'));
        $this->assertSame(30, $m->fresh()->base_stock);
        foreach ([$tiktok, $shopee] as $l) {
            $l->refresh();
            $this->assertNull($l->last_pushed_qty);
            $this->assertNull($l->last_status);
            $this->assertNull($l->last_pushed_price);
            $this->assertNull($l->last_price_status);
        }
    }

    // ---- Perbaikan review akhir ----

    public function test_varian_beda_master_satu_produk_klik_terakhir_menang_foto_dikirim_ulang(): void
    {
        // Foto milik PRODUK (item_id); tiap varian (SKU) = listing sendiri, bisa ditautkan ke master berbeda.
        $this->tiktokConn();
        $this->fakeMarketplace();
        $a = $this->masterBerfoto(1, ['master_sku' => 'A-30', 'name' => 'Serum 30ml']);
        $b = $this->masterBerfoto(1, ['master_sku' => 'B-50', 'name' => 'Serum 50ml']);
        $la = $this->tiktokListing($a, ['seller_sku' => 'A-30', 'variation_id' => 'SKU30']);
        $this->tiktokListing($b, ['seller_sku' => 'B-50', 'variation_id' => 'SKU50']);

        $this->svc()->pushMasterContent($a); // PID1 := foto A
        $this->svc()->pushMasterContent($b); // PID1 := foto B → hash listing A basi → dikosongkan
        $this->assertNull($la->fresh()->photo_hash);

        $r = $this->svc()->pushMasterContent($a); // ingin foto A lagi → HARUS dikirim ulang, bukan skip

        $this->assertSame(['pushed' => 1, 'skipped' => 0, 'failed' => 0], $r);
        $this->assertCount(3, array_filter($this->jejak(), fn ($j) => $j === 'tt-set-foto'));
        $this->assertSame($this->svc()->photoHash($a), $la->fresh()->photo_hash);
    }

    public function test_sibling_master_sama_tak_saling_mereset_klik_ulang_tetap_skip(): void
    {
        // Dua varian produk yang sama ditautkan ke master yang SAMA → hash sama → tak saling mereset.
        $this->tiktokConn();
        $this->fakeMarketplace();
        $m = $this->masterBerfoto(1);
        $l1 = $this->tiktokListing($m, ['seller_sku' => 'SX-30', 'variation_id' => 'SKU30']);
        $l2 = $this->tiktokListing($m, ['seller_sku' => 'SX-50', 'variation_id' => 'SKU50']);

        $this->svc()->pushMasterContent($m);
        $hash = $this->svc()->photoHash($m);
        $this->assertSame($hash, $l1->fresh()->photo_hash);
        $this->assertSame($hash, $l2->fresh()->photo_hash);

        $sebelum = count($this->jejak());
        $r = $this->svc()->pushMasterContent($m, false, true); // Simpan berikutnya (otomatis, diff-guard)

        $this->assertSame(['pushed' => 0, 'skipped' => 2, 'failed' => 0], $r);
        $this->assertCount($sebelum, $this->jejak()); // tanpa HTTP baru
    }

    public function test_shopee_502_html_dari_gateway_tercatat_gagal_dan_bisa_dicoba_ulang(): void
    {
        $this->shopeeConn();
        $this->fakeMarketplace([self::SP_UPDATE => Http::response('<html><body>502 Bad Gateway</body></html>', 502)]);
        $m = $this->masterBerfoto(1);
        $l = $this->shopeeListing($m);

        $this->assertSame('failed', $this->svc()->pushPhotos($l, $m, false));

        $l->refresh();
        $this->assertSame('failed', $l->last_photo_status);
        $this->assertStringContainsString('HTTP 502', (string) $l->last_photo_error);
        $this->assertNull($l->photo_hash); // tak terkunci → klik berikutnya mencoba lagi, bukan skip
        $this->assertSame('failed', $this->svc()->pushPhotos($l, $m, false));
    }

    public function test_token_dan_sign_di_pesan_error_disamarkan(): void
    {
        $this->shopeeConn();
        $url = 'https://partner.shopeemobile.com/api/v2/media_space/upload_image?partner_id=1&timestamp=1&access_token=RAHASIA123&shop_id=123&sign=abcdef0123';
        $this->fakeMarketplace([self::SP_UPLOAD => fn () => throw new ConnectionException("cURL error 28: Operation timed out for {$url}")]);
        $m = $this->masterBerfoto(1);
        $l = $this->shopeeListing($m);

        $this->svc()->pushPhotos($l, $m, true);

        $err = (string) $l->fresh()->last_photo_error;
        $this->assertStringContainsString('timed out', $err);
        $this->assertStringContainsString('access_token=***', $err);
        $this->assertStringContainsString('sign=***', $err);
        $this->assertStringNotContainsString('RAHASIA123', $err);
        $this->assertStringNotContainsString('abcdef0123', $err);
    }

    public function test_flash_menyebut_listing_yang_fotonya_diganti_dan_klik_ganda_tak_salah_hitung(): void
    {
        $this->tiktokConn();
        $this->fakeMarketplace();
        $m = $this->masterBerfoto(1, ['name' => 'Serum X', 'description' => 'Serum wajah']);
        $this->tiktokListing($m);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('marketplace-stock.master.konten', $m))
            ->assertSessionHas('status', 'Dorong konten & foto "Serum X" — foto diganti di 1 listing (1 sinkron OK)');

        // Klik ulang (detik yang sama pun): manual = paksa → foto diganti lagi & tetap terhitung.
        $this->actingAs($admin)->post(route('marketplace-stock.master.konten', $m))
            ->assertSessionHas('status', 'Dorong konten & foto "Serum X" — foto diganti di 1 listing (1 sinkron OK)');
    }

    public function test_mask_secrets_menyamarkan_kredensial_tanpa_mengubah_teks_lain(): void
    {
        $teks = 'timed out for https://h/api?partner_id=1&access_token=AAA&shop_id=9&sign=BBB&shop_cipher=CCC design=ok resign=ok';

        $this->assertSame(
            'timed out for https://h/api?partner_id=1&access_token=***&shop_id=9&sign=***&shop_cipher=*** design=ok resign=ok',
            MarketplaceMasterService::maskSecrets($teks)
        );
    }

    public function test_flash_refresh_listing_menyamarkan_token_saat_error(): void
    {
        $this->mock(MarketplaceMasterService::class, fn ($mock) => $mock->shouldReceive('resolveListings')
            ->andThrow(new \RuntimeException('cURL error 28: timed out for https://partner.shopeemobile.com/api/v2/product/get_item_list?access_token=RAHASIA123&shop_id=1&sign=abcdef')));

        $this->actingAs($this->admin())->post(route('marketplace-stock.resolve'))
            ->assertSessionHas('error', fn (string $e) => str_contains($e, 'access_token=***') && str_contains($e, 'sign=***')
                && ! str_contains($e, 'RAHASIA123') && ! str_contains($e, 'abcdef'));
    }
}
