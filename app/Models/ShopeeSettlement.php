<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeSettlement extends Model
{
    public const POST_PENDING = 'pending';

    public const POST_POSTED = 'posted';

    protected $fillable = [
        'order_sn', 'currency', 'escrow_amount', 'buyer_total_amount',
        'commission_fee', 'service_fee', 'campaign_fee', 'seller_transaction_fee',
        'actual_shipping_fee', 'buyer_paid_shipping_fee', 'shopee_shipping_rebate',
        'escrow_tax', 'withholding_tax', 'total_adjustment_amount',
        'escrow_release_time', 'raw',
        'posting_status', 'journal_id', 'posted_at', 'posted_by',
    ];

    protected $casts = [
        'escrow_amount' => 'decimal:2', 'buyer_total_amount' => 'decimal:2',
        'commission_fee' => 'decimal:2', 'service_fee' => 'decimal:2',
        'campaign_fee' => 'decimal:2', 'seller_transaction_fee' => 'decimal:2',
        'actual_shipping_fee' => 'decimal:2', 'buyer_paid_shipping_fee' => 'decimal:2',
        'shopee_shipping_rebate' => 'decimal:2', 'escrow_tax' => 'decimal:2',
        'withholding_tax' => 'decimal:2', 'total_adjustment_amount' => 'decimal:2',
        'raw' => 'array', 'escrow_release_time' => 'datetime', 'posted_at' => 'datetime',
    ];

    public function isPosted(): bool
    {
        return $this->posting_status === self::POST_POSTED;
    }

    /** Total potongan platform (komisi+layanan+campaign+txn seller+pajak). Ongkir ditampilkan terpisah. */
    public function feeTotal(): float
    {
        return (float) $this->commission_fee + (float) $this->service_fee
            + (float) $this->campaign_fee + (float) $this->seller_transaction_fee
            + (float) $this->escrow_tax + (float) $this->withholding_tax;
    }
}
