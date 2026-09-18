<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cache detail item Shopee (nama/foto/harga) untuk kartu produk di Chat E-commerce. */
class ShopeeProduct extends Model
{
    protected $fillable = ['item_id', 'title', 'image_url', 'price', 'currency'];

    protected $casts = ['price' => 'decimal:2'];
}
