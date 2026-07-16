<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeSkuMap extends Model
{
    protected $fillable = ['shopee_sku', 'product_id', 'qty'];

    protected $casts = ['qty' => 'integer'];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
