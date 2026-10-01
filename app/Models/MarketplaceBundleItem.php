<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu baris resep bundling: `qty` × master komponen di dalam master bundle. */
class MarketplaceBundleItem extends Model
{
    protected $fillable = ['bundle_id', 'component_id', 'qty'];

    protected function casts(): array
    {
        return ['qty' => 'integer'];
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(MarketplaceMaster::class, 'bundle_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(MarketplaceMaster::class, 'component_id');
    }
}
