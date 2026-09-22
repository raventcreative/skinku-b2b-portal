<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceChannelOverride extends Model
{
    protected $fillable = ['product_id', 'channel', 'quantity', 'seeded_at'];

    protected $casts = ['quantity' => 'integer', 'seeded_at' => 'datetime'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
