<?php

namespace App\Support;

/**
 * Encoder Code 128-B → SVG murni (zero-dependency). Dipakai untuk barcode
 * resi / No PO di label pengiriman — tak butuh paket eksternal.
 */
class Barcode
{
    /**
     * Pola lebar modul (bar,spasi,bar,…) untuk tiap simbol Code128 0..106.
     * Tabel baku Code 128; simbol 106 (stop) punya 7 elemen, sisanya 6.
     */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312',
        '132212', '221213', '221312', '231212', '112232', '122132', '122231', '113222',
        '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131',
        '311222', '321122', '321221', '312212', '322112', '322211', '212123', '212321',
        '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121',
        '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321',
        '331121', '312113', '312311', '332111', '314111', '221411', '431111', '111224',
        '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114',
        '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112',
        '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113',
        '114311', '411113', '411311', '113141', '114131', '311141', '411131', '211412',
        '211214', '211232', '2331112',
    ];

    private const START_B = 104;

    private const STOP = 106;

    /**
     * SVG barcode Code128-B dari $value. $module = lebar 1 modul (px),
     * $height = tinggi bar (px). Mengembalikan elemen <svg> lengkap.
     */
    public static function code128(string $value, int $module = 2, int $height = 60): string
    {
        if ($value === '') {
            $value = '0';
        }

        $codes = [self::START_B];
        $sum = self::START_B;

        foreach (str_split($value) as $i => $ch) {
            $ord = ord($ch);
            // Code128-B mencakup ASCII 32..126; di luar itu → '?' (nilai 31).
            $val = ($ord >= 32 && $ord <= 126) ? $ord - 32 : 31;
            $codes[] = $val;
            $sum += $val * ($i + 1);
        }

        $codes[] = $sum % 103; // checksum
        $codes[] = self::STOP;

        $x = 0;
        $rects = '';
        foreach ($codes as $code) {
            foreach (str_split(self::PATTERNS[$code]) as $j => $w) {
                $w = (int) $w * $module;
                if ($j % 2 === 0) { // elemen genap = bar hitam
                    $rects .= '<rect x="'.$x.'" y="0" width="'.$w.'" height="'.$height.'"/>';
                }
                $x += $w;
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$x.'" height="'.$height.'" '
            .'viewBox="0 0 '.$x.' '.$height.'" fill="#000">'.$rects.'</svg>';
    }
}
