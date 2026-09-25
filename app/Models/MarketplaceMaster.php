<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceMaster extends Model
{
    protected $fillable = ['master_sku', 'name', 'product_id', 'base_stock', 'base_price', 'seeded_at'];

    protected function casts(): array
    {
        return [
            'base_stock' => 'integer',
            'base_price' => 'decimal:2',
            'seeded_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(MarketplaceMasterChannel::class, 'master_id');
    }

    public function listings(): HasMany
    {
        return $this->hasMany(MarketplaceListing::class, 'master_id');
    }
}
