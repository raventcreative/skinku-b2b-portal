<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeWalletTransaction extends Model
{
    public const POST_PENDING = 'pending';

    public const POST_POSTED = 'posted';

    protected $fillable = [
        'transaction_id', 'transaction_type', 'kind', 'amount', 'current_balance',
        'money_flow', 'order_sn', 'refund_sn', 'reason', 'status', 'transaction_time',
        'raw', 'posting_status', 'journal_id', 'posted_at', 'posted_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2', 'current_balance' => 'decimal:2',
        'raw' => 'array', 'transaction_time' => 'datetime', 'posted_at' => 'datetime',
    ];

    public function isPosted(): bool
    {
        return $this->posting_status === self::POST_POSTED;
    }
}
