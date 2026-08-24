<?php

namespace Tests\Feature;

use App\Models\ShopeeSettlement;
use App\Services\ShopeeClient;
use App\Services\ShopeeSettlementService;
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

    public function test_store_peta_income_dari_escrow_detail(): void
    {
        $svc = app(ShopeeSettlementService::class);
        // bentuk = elemen response get_escrow_detail_batch (order_sn + order_income + escrow_release_time gabungan)
        $detail = [
            'order_sn' => '2608247FYHUBMG',
            'escrow_release_time' => now()->timestamp,
            'order_income' => [
                'escrow_amount' => 64675, 'buyer_total_amount' => 77665,
                'commission_fee' => 0, 'service_fee' => 0, 'campaign_fee' => 0,
                'seller_transaction_fee' => 0, 'actual_shipping_fee' => 11765,
                'buyer_paid_shipping_fee' => 11765, 'shopee_shipping_rebate' => 0,
                'escrow_tax' => 0, 'withholding_tax' => 0, 'total_adjustment_amount' => 0,
            ],
        ];

        $n = $svc->store([$detail]);

        $this->assertSame(1, $n);
        $row = ShopeeSettlement::where('order_sn', '2608247FYHUBMG')->first();
        $this->assertEquals('64675.00', $row->escrow_amount);
        $this->assertEquals('11765.00', $row->actual_shipping_fee);
        $this->assertSame(ShopeeSettlement::POST_PENDING, $row->posting_status);
        $this->assertNotNull($row->escrow_release_time);
        $this->assertIsArray($row->raw);

        // idempoten: posting_status tak reset kalau sudah posted
        $row->update(['posting_status' => ShopeeSettlement::POST_POSTED]);
        $svc->store([$detail]);
        $this->assertSame(ShopeeSettlement::POST_POSTED, $row->fresh()->posting_status);
    }
}
