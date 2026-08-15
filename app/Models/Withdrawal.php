<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Withdrawal extends Model
{
    protected $fillable = [
        'user_id', 'amount', 'bank', 'no_rekening', 'atas_nama',
        'status', 'note', 'requested_at', 'processed_by', 'processed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'requested_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    /** Mitra pengaju. */
    public function mitra(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Staf HQ yang memproses (approve/tolak/cairkan). */
    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
