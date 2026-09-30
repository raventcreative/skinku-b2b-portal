<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Services\ShopeeClient;
use App\Services\TikTokClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Klien "dorong konten" ke marketplace: TikTok partial_edit (JSON) + Shopee update_item.
 * Keduanya hanya membungkus helper bertanda-tangan yang sudah ada (request / shopCall),
 * jadi yang diuji di sini: endpoint benar + argumen (token, cipher/shop_id, id produk, field)
 * sampai ke request yang dikirim di posisi yang benar.
 */
class ContentClientTest extends TestCase
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

    public function test_tiktok_partial_edit_product_memanggil_endpoint_dan_mengirim_field_sebagai_json(): void
    {
        $this->configureTikTok();
        Http::preventStrayRequests();
        // URL bertanda-tangan punya query string → pola fake WAJIB diakhiri `*`.
        Http::fake([
            '*/product/202309/products/PID123/partial_edit*' => Http::response(['code' => 0, 'message' => 'ok', 'data' => ['marker' => 'x']]),
        ]);

        $data = app(TikTokClient::class)->partialEditProduct('tok', 'cipher', 'PID123', ['description' => 'Halo']);

        $this->assertSame(['marker' => 'x'], $data);
        Http::assertSentCount(1);
        Http::assertSent(function ($req) {
            return $req->method() === 'POST'
                && str_contains($req->url(), '/product/202309/products/PID123/partial_edit')
                && str_contains($req->url(), 'shop_cipher=cipher')
                && $req->hasHeader('x-tts-access-token', 'tok')
                && json_decode($req->body(), true) === ['description' => 'Halo'];
        });
    }

    public function test_shopee_update_item_memanggil_endpoint_dan_mengirim_item_id_serta_field_di_body(): void
    {
        $this->configureShopee();
        Http::preventStrayRequests();
        // URL bertanda-tangan punya query string → pola fake WAJIB diakhiri `*`.
        Http::fake([
            '*/api/v2/product/update_item*' => Http::response(['error' => '', 'message' => '', 'response' => ['marker' => 'x']]),
        ]);

        $res = app(ShopeeClient::class)->updateItem('tok', 'SHOP1', 555, ['description' => 'Halo', 'weight' => 0.25]);

        $this->assertSame('x', $res['response']['marker']);
        Http::assertSentCount(1);
        Http::assertSent(function ($req) {
            $body = json_decode($req->body(), true);

            return $req->method() === 'POST'
                && str_contains($req->url(), '/api/v2/product/update_item')
                && str_contains($req->url(), 'access_token=tok')
                && str_contains($req->url(), 'shop_id=SHOP1')
                && $body['item_id'] === 555
                && $body['description'] === 'Halo'
                && $body['weight'] === 0.25;
        });
    }
}
