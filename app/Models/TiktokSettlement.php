<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TiktokSettlement extends Model
{
    public const POST_PENDING = 'pending';

    public const POST_POSTED = 'posted';

    protected $fillable = [
        'tiktok_statement_id', 'payment_status', 'currency',
        'revenue_amount', 'fee_amount', 'adjustment_amount', 'settlement_amount',
        'order_ids', 'raw', 'statement_time', 'paid_time',
        'posting_status', 'journal_id', 'posted_at', 'posted_by',
        'kind', 'kind_raw',
    ];

    protected $casts = [
        'revenue_amount' => 'decimal:2',
        'fee_amount' => 'decimal:2',
        'adjustment_amount' => 'decimal:2',
        'settlement_amount' => 'decimal:2',
        'order_ids' => 'array',
        'raw' => 'array',
        'statement_time' => 'datetime',
        'paid_time' => 'datetime',
        'posted_at' => 'datetime',
    ];

    public function isPosted(): bool
    {
        return $this->posting_status === self::POST_POSTED;
    }
}
