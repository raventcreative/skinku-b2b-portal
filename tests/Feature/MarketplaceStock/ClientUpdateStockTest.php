<?php

namespace Tests\Feature\MarketplaceStock;

use App\Services\ShopeeClient;
use App\Services\TikTokClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientUpdateStockTest extends TestCase
{
    // ---- Shopee ----

    public function test_shopee_update_stock_mengirim_item_id_model_id_dan_stock_benar(): void
    {
        config()->set('services.shopee.partner_id', 1);
        config()->set('services.shopee.partner_key', 'k');
        config()->set('services.shopee.api_base', 'https://partner.shopeemobile.com');

        Http::fake(['*' => Http::response(['error' => '', 'response' => []])]);

        app(ShopeeClient::class)->updateStock('tok', '123', 555, 0, 42);

        Http::assertSent(function ($req) {
            $b = $req->data();

            return str_contains($req->url(), '/api/v2/product/update_stock')
                && $b['item_id'] === 555
                && $b['stock_list'][0]['model_id'] === 0
                && $b['stock_list'][0]['seller_stock'][0]['stock'] === 42;
        });
    }

    public function test_shopee_get_item_list_mengirim_offset_page_size_dan_status_normal(): void
    {
        config()->set('services.shopee.partner_id', 1);
        config()->set('services.shopee.partner_key', 'k');
        config()->set('services.shopee.api_base', 'https://partner.shopeemobile.com');

        Http::fake([
            '*get_item_list*' => Http::response(['error' => '', 'response' => ['item' => [['item_id' => 555]]]]),
        ]);

        $res = app(ShopeeClient::class)->getItemList('tok', '123', 10, 20);

        $this->assertSame(555, $res['response']['item'][0]['item_id']);
        Http::assertSent(function ($req) {
            return str_contains($req->url(), '/api/v2/product/get_item_list')
                && $req->method() === 'GET'
                && str_contains($req->url(), 'offset=10')
                && str_contains($req->url(), 'page_size=20')
                && str_contains($req->url(), 'item_status=NORMAL');
        });
    }

    public function test_shopee_get_model_list_mengirim_item_id(): void
    {
        config()->set('services.shopee.partner_id', 1);
        config()->set('services.shopee.partner_key', 'k');
        config()->set('services.shopee.api_base', 'https://partner.shopeemobile.com');

        Http::fake([
            '*get_model_list*' => Http::response(['error' => '', 'response' => ['model' => [['model_id' => 9]]]]),
        ]);

        $res = app(ShopeeClient::class)->getModelList('tok', '123', 555);

        $this->assertSame(9, $res['response']['model'][0]['model_id']);
        Http::assertSent(function ($req) {
            return str_contains($req->url(), '/api/v2/product/get_model_list')
                && $req->method() === 'GET'
                && str_contains($req->url(), 'item_id=555');
        });
    }

    // ---- TikTok ----

    public function test_tiktok_update_stock_mengirim_sku_id_warehouse_dan_quantity_benar(): void
    {
        config()->set('services.tiktok.api_base', 'https://open-api.tiktokglobalshop.com');
        config()->set('services.tiktok.app_key', 'a');
        config()->set('services.tiktok.app_secret', 's');

        Http::fake(['*' => Http::response(['code' => 0, 'data' => []])]);

        app(TikTokClient::class)->updateStock('tok', 'cipher', 'PROD1', 'SKU1', 'WH1', 7);

        Http::assertSent(function ($req) {
            $b = $req->data();

            return str_contains($req->url(), '/product/202309/products/PROD1/inventory/update')
                && $b['skus'][0]['id'] === 'SKU1'
                && $b['skus'][0]['inventory'][0]['warehouse_id'] === 'WH1'
                && $b['skus'][0]['inventory'][0]['quantity'] === 7;
        });
    }

    public function test_tiktok_search_products_mengirim_page_size_page_token_dan_status_activate(): void
    {
        config()->set('services.tiktok.api_base', 'https://open-api.tiktokglobalshop.com');
        config()->set('services.tiktok.app_key', 'a');
        config()->set('services.tiktok.app_secret', 's');

        Http::fake([
            '*/product/202309/products/search*' => Http::response(['code' => 0, 'data' => ['products' => [['id' => 'PROD1']]]]),
        ]);

        $data = app(TikTokClient::class)->searchProducts('tok', 'cipher', 20, 'PAGE2');

        $this->assertSame('PROD1', $data['products'][0]['id']);
        Http::assertSent(function ($req) {
            $b = $req->data();

            return str_contains($req->url(), '/product/202309/products/search')
                && str_contains($req->url(), 'page_size=20')
                && str_contains($req->url(), 'page_token=PAGE2')
                && $b['status'] === 'ACTIVATE';
        });
    }

    public function test_tiktok_search_products_tanpa_page_token_tidak_kirim_query_page_token(): void
    {
        config()->set('services.tiktok.api_base', 'https://open-api.tiktokglobalshop.com');
        config()->set('services.tiktok.app_key', 'a');
        config()->set('services.tiktok.app_secret', 's');

        Http::fake(['*' => Http::response(['code' => 0, 'data' => []])]);

        app(TikTokClient::class)->searchProducts('tok', 'cipher');

        Http::assertSent(fn ($req) => str_contains($req->url(), '/product/202309/products/search')
            && ! str_contains($req->url(), 'page_token'));
    }

    public function test_tiktok_get_warehouses_memanggil_endpoint_logistics(): void
    {
        config()->set('services.tiktok.api_base', 'https://open-api.tiktokglobalshop.com');
        config()->set('services.tiktok.app_key', 'a');
        config()->set('services.tiktok.app_secret', 's');

        Http::fake([
            '*/logistics/202309/warehouses*' => Http::response(['code' => 0, 'data' => ['warehouses' => [['id' => 'WH1']]]]),
        ]);

        $data = app(TikTokClient::class)->getWarehouses('tok', 'cipher');

        $this->assertSame('WH1', $data['warehouses'][0]['id']);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/logistics/202309/warehouses') && $req->method() === 'GET');
    }
}
