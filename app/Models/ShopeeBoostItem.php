<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Produk Shopee yang dijaga tetap "naik" (Naikkan Produk otomatis, maks 5). Lihat ShopeeBoostService. */
class ShopeeBoostItem extends Model
{
    protected $fillable = ['item_id', 'title', 'last_boosted_at', 'boosted_until', 'last_status', 'last_error', 'created_by'];

    protected function casts(): array
    {
        return [
            'item_id' => 'integer',
            'last_boosted_at' => 'datetime',
            'boosted_until' => 'datetime',
        ];
    }

    /** Masih dalam masa naik 4 jam (menurut putaran terakhir). */
    public function sedangNaik(): bool
    {
        return $this->boosted_until !== null && $this->boosted_until->isFuture();
    }
}
