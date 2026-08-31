<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Peta SKU untuk parser Report Bot (TikTok Income): SKU ID → kategori × qty.
 * Bundle = beberapa baris ber-sku_id sama. Menggantikan konstanta SKU_MAP di
 * TikTokIncomeN8nService (yang jadi seed + fallback) — kini bisa disunting dari
 * UI tanpa deploy.
 */
class ReportSkuMap extends Model
{
    protected $fillable = ['sku_id', 'category', 'qty', 'note'];

    protected function casts(): array
    {
        return ['qty' => 'integer'];
    }
}
