<?php

namespace Tests\Feature;

use App\Models\ShopeeSettlement;
use App\Services\ShopeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopeeSettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_simpan_dan_hitung_fee_total(): void
    {
        $s = ShopeeSettlement::create([
            'order_sn' => 'S-1', 'currency' => 'IDR',
            'escrow_amount' => 64675, 'buyer_total_amount' => 77665,
            'commission_fee' => 1000, 'service_fee' => 500, 'campaign_fee' => 0,
            'seller_transaction_fee' => 0, 'actual_shipping_fee' => 11765,
            'buyer_paid_shipping_fee' => 11765, 'shopee_shipping_rebate' => 0,
            'escrow_tax' => 0, 'withholding_tax' => 0, 'total_adjustment_amount' => 0,
            'posting_status' => ShopeeSettlement::POST_PENDING,
        ]);

        $this->assertFalse($s->isPosted());
        $this->assertEquals('64675.00', $s->fresh()->escrow_amount);
        $this->assertEquals(1500.0, $s->feeTotal()); // commission + service + campaign + seller_txn + tax
    }

    public function test_client_escrow_list_kirim_path_dan_sign(): void
    {
        config(['services.shopee.partner_id' => '123', 'services.shopee.partner_key' => 'secret',
            'services.shopee.api_base' => 'https://partner.example.com']);
        Http::fake(['*get_escrow_list*' => Http::response(['response' => ['escrow_list' => [], 'more' => false]])]);

        app(ShopeeClient::class)->getEscrowList('ACCESS', 'SHOP', 100, 200, 1, 100);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/v2/payment/get_escrow_list')
            && str_contains($r->url(), 'release_time_from=100') && str_contains($r->url(), 'sign='));
    }
}
