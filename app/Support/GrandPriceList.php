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

    /** SKU produk (dari master prod) => harga grand. Dipakai migrasi 000078. */
    public const PRICES_BY_SKU = [
        'SOAP-1' => 22000,
        'SK-YK' => 34000,
        'JPE-100ML' => 22000,
        'HG-FC-20ml' => 32000,
        'MZ-500ML' => 26000,
        'REI-30G' => 23000,
        'HG-1' => 13500,
        'HK-1' => 13500,
        'AG-DC-1' => 35000,
        'YR-NC-1' => 41000,
    ];

    /** Set price_grand berdasar SKU (cocok persis). No-op untuk SKU tak dikenal. */
    public static function applyBySku(): void
    {
        foreach (self::PRICES_BY_SKU as $sku => $price) {
            DB::table('products')->where('sku', $sku)->update(['price_grand' => $price]);
        }
    }
}
