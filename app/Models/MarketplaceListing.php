<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceListing extends Model
{
    protected $fillable = [
        'channel', 'seller_sku', 'item_id', 'variation_id', 'warehouse_id', 'title',
        'last_pushed_qty', 'last_status', 'last_error', 'last_pushed_at', 'resolved_at',
    ];

    protected $casts = [
        'last_pushed_qty' => 'integer',
        'last_pushed_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];
}
