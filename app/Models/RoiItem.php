<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoiItem extends Model
{
    protected $fillable = [
        'product_id', 'selling_price', 'modal', 'packing', 'proses_order',
        'admin_pct', 'voucher_pct', 'komisi_pct', 'komisi_cap', 'mall_pct',
        'pajak_pct', 'operasional_pct', 'affiliate_pct',
    ];

    protected function casts(): array
    {
        return [
            'selling_price' => 'integer', 'modal' => 'integer', 'packing' => 'integer',
            'proses_order' => 'integer', 'komisi_cap' => 'integer',
            'admin_pct' => 'float', 'voucher_pct' => 'float', 'komisi_pct' => 'float',
            'mall_pct' => 'float', 'pajak_pct' => 'float', 'operasional_pct' => 'float',
            'affiliate_pct' => 'float',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
