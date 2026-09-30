<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Services\ShopeeClient;
use App\Services\TikTokClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Klien upload gambar MULTIPART untuk "Dorong Foto": TikTok images/upload + Shopee media_space/upload_image.
 * Helper JSON lama (request() / shopCall()) tak muat file, jadi keduanya method baru. Yang diuji:
 * endpoint benar, file + field form betul-betul terkirim sbg multipart (dicek di BODY MENTAH, bukan cuma
 * niat Laravel), tanda tangan mengikuti aturan tiap platform, dan nilai kembali (uri / image_id)
 * diambil dari respons — plus galat platform muncul sbg exception (Task berikutnya merekamnya).
 */
class PhotoUploadClientTest extends TestCase
{
    private function configureTikTok(): void
    {
        config()->set('services.tiktok.api_base', 'https://open-api.tiktokglobalshop.com');
        config()->set('services.tiktok.app_key', 'a');
        config()->set('services.tiktok.app_secret', 's');
    }

    private function configureShopee(): void
    {
        config()->set('services.shopee.partner_id', 1);
        config()->set('services.shopee.partner_key', 'k');
        config()->set('services.shopee.api_base', 'https://partner.shopeemobile.com');
    }

    /** Isi bagian multipart (mentah di kabel) ber-name="$name"; null bila bagian itu tak ada. */
    private function multipartPart(Request $req, string $name): ?string
    {
        // tiap bagian = baris header (Content-Disposition, Content-Length, …) + baris kosong + isi
        $pattern = '/name="'.preg_quote($name, '/').'"[^\r\n]*\r\n(?:[^\r\n]+\r\n)*\r\n(.*?)\r\n--/s';

        return preg_match($pattern, $req->body(), $m) === 1 ? $m[1] : null;
    }

    /** Query string URL request → array (termasuk `sign`). */
    private function queryOf(Request $req): array
    {
        parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    // ---- TikTok ----

    public function test_tiktok_upload_image_mengirim_multipart_ke_endpoint_dan_mengembalikan_uri(): void
    {
        $this->configureTikTok();
        Http::preventStrayRequests();
        // URL bertanda-tangan punya query string → pola fake WAJIB diakhiri `*`.
        Http::fake([
            '*/product/202309/images/upload*' => Http::response(['code' => 0, 'message' => 'ok', 'data' => ['uri' => 'tos://img1', 'url' => 'https://x/img1.jpg']]),
        ]);

        $uri = app(TikTokClient::class)->uploadImage('tok', 'cip', 'BYTES', 'a.jpg');

        $this->assertSame('tos://img1', $uri);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $req) {
            return $req->method() === 'POST'
                && strtok($req->url(), '?') === 'https://open-api.tiktokglobalshop.com/product/202309/images/upload'
                && str_contains($req->url(), 'shop_cipher=cip')
                && $req->hasHeader('x-tts-access-token', 'tok')
                && $req->isMultipart()
                // file = field `data` dgn nama aslinya; use_case default = field form biasa di sampingnya
                && str_contains($req->body(), 'name="data"; filename="a.jpg"')
                && $this->multipartPart($req, 'data') === 'BYTES'
                && $this->multipartPart($req, 'use_case') === 'MAIN_IMAGE';
        });
    }

    public function test_tiktok_upload_image_ditandatangani_dengan_body_kosong(): void
    {
        // Aturan TikTok: body multipart TIDAK ikut tanda tangan → sign hanya dari path + query terurut.
        $this->configureTikTok();
        Http::preventStrayRequests();
        Http::fake(['*/product/202309/images/upload*' => Http::response(['code' => 0, 'message' => 'ok', 'data' => ['uri' => 'tos://img1']])]);

        app(TikTokClient::class)->uploadImage('tok', 'cip', 'BYTES', 'a.jpg');

        Http::assertSent(function (Request $req) {
            $query = $this->queryOf($req);
            $sign = $query['sign'] ?? '';
            unset($query['sign']);

            return $sign !== ''
                && isset($query['app_key'], $query['timestamp'])
                && $sign === app(TikTokClient::class)->sign('/product/202309/images/upload', $query, '');
        });
    }

    public function test_tiktok_upload_image_tanpa_shop_cipher_dan_dengan_use_case_lain(): void
    {
        $this->configureTikTok();
        Http::preventStrayRequests();
        Http::fake(['*/product/202309/images/upload*' => Http::response(['code' => 0, 'message' => 'ok', 'data' => ['uri' => 'tos://desc1']])]);

        $uri = app(TikTokClient::class)->uploadImage('tok', null, 'PNGBYTES', 'b.png', 'DESCRIPTION_IMAGE');

        $this->assertSame('tos://desc1', $uri);
        Http::assertSent(function (Request $req) {
            return ! str_contains($req->url(), 'shop_cipher')
                && str_contains($req->body(), 'name="data"; filename="b.png"')
                && $this->multipartPart($req, 'data') === 'PNGBYTES'
                && $this->multipartPart($req, 'use_case') === 'DESCRIPTION_IMAGE';
        });
    }

    public function test_tiktok_upload_image_ditolak_tiktok_melempar_exception_dengan_pesannya(): void
    {
        $this->configureTikTok();
        Http::preventStrayRequests();
        Http::fake(['*/product/202309/images/upload*' => Http::response(['code' => 10001, 'message' => 'Image size is too small'])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Image size is too small');

        app(TikTokClient::class)->uploadImage('tok', 'cip', 'BYTES', 'a.jpg');
    }

    public function test_tiktok_upload_image_sukses_tanpa_uri_melempar_exception_bukan_type_error(): void
    {
        // Kontrak return `string`: respons code=0 tapi tanpa data.uri harus jadi galat yang bisa
        // dibaca (dicatat ke last_photo_error), bukan TypeError "null returned".
        $this->configureTikTok();
        Http::preventStrayRequests();
        Http::fake(['*/product/202309/images/upload*' => Http::response(['code' => 0, 'message' => 'ok', 'data' => []])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('uri');

        app(TikTokClient::class)->uploadImage('tok', 'cip', 'BYTES', 'a.jpg');
    }

    // ---- Shopee ----

    public function test_shopee_upload_image_mengirim_multipart_ke_endpoint_dan_mengembalikan_image_id(): void
    {
        $this->configureShopee();
        Http::preventStrayRequests();
        // URL bertanda-tangan punya query string → pola fake WAJIB diakhiri `*`.
        Http::fake([
            '*/api/v2/media_space/upload_image*' => Http::response(['error' => '', 'message' => '', 'response' => ['image_info' => ['image_id' => 's0abc']]]),
        ]);

        $imageId = app(ShopeeClient::class)->uploadImage('tok', 'SHOP1', 'BYTES', 'a.jpg');

        $this->assertSame('s0abc', $imageId);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $req) {
            return $req->method() === 'POST'
                && strtok($req->url(), '?') === 'https://partner.shopeemobile.com/api/v2/media_space/upload_image'
                && str_contains($req->url(), 'partner_id=1')
                && str_contains($req->url(), 'access_token=tok')
                && str_contains($req->url(), 'shop_id=SHOP1')
                && $req->isMultipart()
                // file = field `image` dgn nama aslinya
                && str_contains($req->body(), 'name="image"; filename="a.jpg"')
                && $this->multipartPart($req, 'image') === 'BYTES';
        });
    }

    public function test_shopee_upload_image_ditandatangani_seperti_api_toko_tanpa_body(): void
    {
        // Sign Shopee tak menyertakan body → base = partner_id + path + timestamp + access_token + shop_id
        // (sama persis dgn shopCall), jadi multipart aman.
        $this->configureShopee();
        Http::preventStrayRequests();
        Http::fake(['*/api/v2/media_space/upload_image*' => Http::response(['error' => '', 'message' => '', 'response' => ['image_info' => ['image_id' => 's0abc']]])]);

        app(ShopeeClient::class)->uploadImage('tok', 'SHOP1', 'BYTES', 'a.jpg');

        Http::assertSent(function (Request $req) {
            $query = $this->queryOf($req);

            return isset($query['sign'], $query['timestamp'])
                && $query['sign'] === app(ShopeeClient::class)->sign('/api/v2/media_space/upload_image', (int) $query['timestamp'], 'tok', 'SHOP1');
        });
    }

    public function test_shopee_upload_image_ditolak_shopee_melempar_exception_dengan_pesannya(): void
    {
        $this->configureShopee();
        Http::preventStrayRequests();
        Http::fake(['*/api/v2/media_space/upload_image*' => Http::response(['error' => 'error_param', 'message' => 'Image format not supported'])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Image format not supported');

        app(ShopeeClient::class)->uploadImage('tok', 'SHOP1', 'BYTES', 'a.jpg');
    }

    public function test_shopee_upload_image_sukses_tanpa_image_id_melempar_exception_bukan_type_error(): void
    {
        // Kontrak return `string`: error kosong tapi tanpa image_id harus jadi galat terbaca.
        $this->configureShopee();
        Http::preventStrayRequests();
        Http::fake(['*/api/v2/media_space/upload_image*' => Http::response(['error' => '', 'message' => '', 'response' => []])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('respons tanpa image_id.'); // tanpa rincian → tak ada "()" menggantung

        app(ShopeeClient::class)->uploadImage('tok', 'SHOP1', 'BYTES', 'a.jpg');
    }

    public function test_shopee_upload_image_galat_per_gambar_di_image_info_list_ikut_di_pesan_exception(): void
    {
        // Skema respons Shopee: galat PER-GAMBAR ada di image_info_list[i].error/message, sedangkan
        // `error` top-level bisa tetap kosong → tanpa ini user hanya melihat "tanpa image_id", tak tahu sebabnya.
        $this->configureShopee();
        Http::preventStrayRequests();
        Http::fake(['*/api/v2/media_space/upload_image*' => Http::response([
            'error' => '',
            'message' => '',
            'response' => ['image_info_list' => [['id' => 0, 'error' => 'error_param', 'message' => 'Image size exceeds limit', 'image_info' => null]]],
        ])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('error_param Image size exceeds limit');

        app(ShopeeClient::class)->uploadImage('tok', 'SHOP1', 'BYTES', 'a.jpg');
    }
}
