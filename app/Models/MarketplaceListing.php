<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceListing extends Model
{
    protected $fillable = [
        'channel', 'seller_sku', 'item_id', 'variation_id', 'warehouse_id', 'title',
        'master_id',
        'last_pushed_qty', 'last_status', 'last_error', 'last_pushed_at', 'resolved_at',
        'last_pushed_price', 'last_price_status', 'last_price_error', 'last_price_pushed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_pushed_qty' => 'integer',
            'last_pushed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'last_pushed_price' => 'decimal:2',
            'last_price_pushed_at' => 'datetime',
        ];
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(MarketplaceMaster::class, 'master_id');
    }
}
