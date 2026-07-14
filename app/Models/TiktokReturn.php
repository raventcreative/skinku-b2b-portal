<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TiktokReturn extends Model
{
    public const REVIEW_PENDING = 'pending';

    public const REVIEW_RESTOCKED = 'restocked';   // layak jual → stok ditambah

    public const REVIEW_REJECTED = 'rejected';     // cacat → tidak masuk stok

    protected $fillable = [
        'tiktok_return_id', 'tiktok_order_id', 'status', 'return_type', 'line_items',
        'review_status', 'review_note', 'return_created_at', 'reviewed_at', 'reviewed_by',
    ];

    protected $casts = [
        'line_items' => 'array',
        'return_created_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];
}
