<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoiSetting extends Model
{
    protected $fillable = [
        'admin_pct', 'voucher_pct', 'komisi_pct', 'komisi_cap', 'mall_pct',
        'pajak_pct', 'operasional_pct', 'affiliate_pct', 'packing_default', 'proses_order_default',
    ];

    protected function casts(): array
    {
        return [
            'admin_pct' => 'float', 'voucher_pct' => 'float', 'komisi_pct' => 'float',
            'komisi_cap' => 'integer', 'mall_pct' => 'float', 'pajak_pct' => 'float',
            'operasional_pct' => 'float', 'affiliate_pct' => 'float',
            'packing_default' => 'integer', 'proses_order_default' => 'integer',
        ];
    }

    /** Default pabrik (dipakai saat baris belum ada). */
    public const DEFAULTS = [
        'admin_pct' => 8, 'voucher_pct' => 4.5, 'komisi_pct' => 5.5, 'komisi_cap' => 650000,
        'mall_pct' => 1.8, 'pajak_pct' => 0.5, 'operasional_pct' => 3, 'affiliate_pct' => 5,
        'packing_default' => 1000, 'proses_order_default' => 1250,
    ];

    /** Baris setelan tunggal (id=1); dibuat dgn DEFAULTS bila belum ada. */
    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], self::DEFAULTS);
    }
}
