<?php

namespace Tests\Feature;

use App\Models\ShopeeSettlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
