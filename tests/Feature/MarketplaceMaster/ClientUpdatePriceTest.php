<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Services\ShopeeClient;
use App\Services\TikTokClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientUpdatePriceTest extends TestCase
{
    public function test_tiktok_update_price_mengirim_amount_string_dan_currency(): void
    {
        Http::fake(['*' => Http::response(['code' => 0, 'data' => []])]);
        app(TikTokClient::class)->updatePrice('tok', 'cipher', 'PID1', 'SKU1', 39000);

        Http::assertSent(function ($req) {
            $body = json_decode($req->body(), true);

            return str_contains($req->url(), '/product/202309/products/PID1/prices/update')
                && $body['skus'][0]['id'] === 'SKU1'
                && $body['skus'][0]['price']['amount'] === '39000'
                && $body['skus'][0]['price']['currency'] === 'IDR';
        });
    }

    public function test_shopee_update_price_mengirim_model_id_dan_original_price(): void
    {
        Http::fake(['*' => Http::response(['error' => '', 'response' => []])]);
        app(ShopeeClient::class)->updatePrice('tok', '123', 555, 66, 42000);

        Http::assertSent(function ($req) {
            return str_contains($req->url(), '/api/v2/product/update_price')
                && $req['item_id'] === 555
                && $req['price_list'][0]['model_id'] === 66
                && (int) $req['price_list'][0]['original_price'] === 42000;
        });
    }
}
