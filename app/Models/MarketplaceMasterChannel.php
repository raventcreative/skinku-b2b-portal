<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceMasterChannel extends Model
{
    protected $fillable = ['master_id', 'channel', 'stock', 'price', 'seeded_at'];

    protected function casts(): array
    {
        return [
            'stock' => 'integer',
            'price' => 'decimal:2',
            'seeded_at' => 'datetime',
        ];
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(MarketplaceMaster::class, 'master_id');
    }
}
