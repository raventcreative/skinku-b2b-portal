<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Harga Grand Distributor per produk dari PRICELIST resmi SKINKU.
 * Kunci = nama produk (lowercase). Dipakai migrasi 000076 untuk seed kolom
 * price_grand, dicocokkan LOWER(TRIM(products.name)). Aman & idempoten:
 * produk yang namanya tak ada di sini dibiarkan (fallback ke price_distributor).
 */
class GrandPriceList
{
    /** nama produk (lowercase) => harga grand (rupiah). */
    public const PRICES = [
        'sabun' => 22000,
        'serum/lotion' => 34000,
        'scrub' => 22000,
        'serum wajah' => 32000,
        'sabun cair' => 26000,
        'reina underarm' => 23000,
        'face mist' => 13500,
        'mouth spray' => 13500,
        'day cream' => 35000,
        'night cream' => 41000,
    ];

    /** Set price_grand untuk produk yang namanya cocok (case-insensitive, trim). */
    public static function apply(): void
    {
        foreach (self::PRICES as $name => $price) {
            DB::table('products')
                ->whereRaw('LOWER(TRIM(name)) = ?', [$name])
                ->update(['price_grand' => $price]);
        }
    }
}
