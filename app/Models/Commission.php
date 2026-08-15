<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Commission extends Model
{
    protected $fillable = [
        'user_id', 'source_po_id', 'source_user_id',
        'type', 'level', 'rate', 'base_amount', 'amount', 'status',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
        'base_amount' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    /** Penerima komisi (upline). */
    public function penerima(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** PO sumber komisi (nullable). */
    public function sourcePo(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'source_po_id');
    }

    /** Downline pembeli yang memicu komisi ini. */
    public function downline(): BelongsTo
    {
        return $this->belongsTo(User::class, 'source_user_id');
    }
}
