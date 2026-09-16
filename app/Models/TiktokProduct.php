<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cache detail produk TikTok (judul/foto/harga) untuk kartu produk di chat. */
class TiktokProduct extends Model
{
    protected $fillable = ['product_id', 'title', 'image_url', 'price', 'currency'];

    protected function casts(): array
    {
        return ['price' => 'integer'];
    }
}
