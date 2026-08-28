<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Override target per-bulan (budget/views/gmv/margin). Bila baris untuk suatu
 * bulan ada, nilainya menggantikan setelan global (AppSetting) untuk bulan itu.
 * Kolom yang null = "pakai global" (override sebagian saja boleh).
 */
class KolMonthlyTarget extends Model
{
    protected $fillable = ['month', 'budget', 'views_target', 'gmv_target', 'margin', 'notes'];

    protected function casts(): array
    {
        return [
            'budget' => 'integer',
            'views_target' => 'integer',
            'gmv_target' => 'integer',
            'margin' => 'float',
        ];
    }

    /** Baris override untuk bulan (Carbon atau 'Y-m'), atau null bila tak ada. */
    public static function forMonth(Carbon|string $month): ?self
    {
        $ym = $month instanceof Carbon ? $month->format('Y-m') : $month;

        return static::where('month', $ym)->first();
    }
}
