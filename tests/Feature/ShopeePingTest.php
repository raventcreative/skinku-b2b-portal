<?php

namespace Tests\Feature;

use App\Services\ShopeeClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopeePingTest extends TestCase
{
    private function configureShopee(): void
    {
        config([
            'services.shopee.partner_id' => '1241970',
            'services.shopee.partner_key' => 'testsecret',
            'services.shopee.api_base' => 'https://partner.test-stable.shopeemobile.com',
        ]);
    }

    public function test_get_shops_by_partner_mengirim_sign_dan_mengembalikan_daftar(): void
    {
        $this->configureShopee();
        Http::fake([
            '*get_shops_by_partner*' => Http::response(['error' => '', 'authed_shop_list' => [['shop_id' => 111]]]),
        ]);

        $res = app(ShopeeClient::class)->getShopsByPartner();

        $this->assertSame([['shop_id' => 111]], $res['authed_shop_list']);
        Http::assertSent(function ($req) {
            return str_contains($req->url(), '/api/v2/public/get_shops_by_partner')
                && str_contains($req->url(), 'partner_id=1241970')
                && str_contains($req->url(), 'sign=');
        });
    }

    public function test_ping_command_sukses_saat_sign_diterima(): void
    {
        $this->configureShopee();
        Http::fake(['*' => Http::response(['error' => '', 'authed_shop_list' => []])]);

        $this->artisan('shopee:ping')->assertExitCode(0);
    }

    public function test_ping_command_gagal_saat_wrong_sign(): void
    {
        $this->configureShopee();
        Http::fake(['*' => Http::response(['error' => 'error_sign', 'message' => 'wrong sign'])]);

        $this->artisan('shopee:ping')->assertExitCode(1);
    }

    public function test_ping_command_gagal_saat_belum_dikonfigurasi(): void
    {
        config(['services.shopee.partner_id' => '', 'services.shopee.partner_key' => '']);

        $this->artisan('shopee:ping')->assertExitCode(1);
    }
}
