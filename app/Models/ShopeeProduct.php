<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cache detail item Shopee (nama/foto/harga) untuk kartu produk di Chat E-commerce & foto di Naikkan Produk. */
class ShopeeProduct extends Model
{
    protected $fillable = ['item_id', 'title', 'image_url', 'price', 'currency'];

    protected $casts = ['price' => 'decimal:2'];

    /** Simpan satu item dari respons v2.product.get_item_base_info (response.item_list[]). */
    public static function simpanDariBaseInfo(array $info): void
    {
        static::updateOrCreate(['item_id' => (string) $info['item_id']], [
            'title' => (string) ($info['item_name'] ?? ''),
            'image_url' => (string) (data_get($info, 'image.image_url_list.0') ?? ''),
            'price' => null,
            'currency' => 'IDR',
        ]);
    }
}
