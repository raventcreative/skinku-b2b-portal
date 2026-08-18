<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JoinPackageItem extends Model
{
    protected $fillable = ['join_package_id', 'product_id', 'qty'];

    protected $casts = ['qty' => 'integer'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function joinPackage(): BelongsTo
    {
        return $this->belongsTo(JoinPackage::class);
    }
}
