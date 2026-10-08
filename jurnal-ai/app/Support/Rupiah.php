<?php

namespace App\Support;

/**
 * Parsing & format angka rupiah. AI dan dokumen Indonesia menulis uang dalam
 * banyak rasa: "Rp 1.250.000", "1,250,000.50", "1.250.000,75", "85rb", "3jt".
 * Semuanya harus jadi float yang sama supaya jurnal tidak selisih.
 */
class Rupiah
{
    /**
     * Angka apa pun (string/int/float) → float rupiah. Mengembalikan null kalau
     * benar-benar tidak ada angka yang bisa dibaca.
     */
    public static function parse(mixed $value): ?float
    {
        if ($value === null || $value === '' || is_bool($value)) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return round((float) $value, 2);
        }
        if (! is_string($value)) {
            return null;
        }

        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        // Negatif bisa ditulis "-5000" atau "(5.000)" (gaya akuntansi).
        $negative = str_starts_with($raw, '-') || (str_starts_with($raw, '(') && str_ends_with($raw, ')'));

        // Buang simbol mata uang & spasi, sisakan digit, pemisah, dan sufiks singkat.
        $s = strtolower($raw);
        $s = preg_replace('/\b(rp|idr)\b\.?/i', '', $s) ?? $s;
        $s = str_replace([' ', "\u{00A0}", '(', ')', '+'], '', $s);

        // Sufiks skala: 85rb / 85k → 85.000 ; 3jt / 3m → 3.000.000 ; 1,5jt → 1.500.000
        $multiplier = 1;
        if (preg_match('/^(-?[\d.,]+)\s*(rb|ribu|k|jt|juta|m|miliar|milyar|b)$/', $s, $m) === 1) {
            $s = $m[1];
            $multiplier = match ($m[2]) {
                'rb', 'ribu', 'k' => 1_000,
                'jt', 'juta', 'm' => 1_000_000,
                'miliar', 'milyar', 'b' => 1_000_000_000,
                default => 1,
            };
        }

        $s = preg_replace('/[^0-9.,\-]/', '', $s) ?? '';
        $s = ltrim($s, '-');
        if ($s === '' || preg_match('/\d/', $s) !== 1) {
            return null;
        }

        $number = self::normalizeSeparators($s);
        if ($number === null) {
            return null;
        }

        $out = round($number * $multiplier, 2);

        return $negative ? -$out : $out;
    }

    /** Seperti parse() tapi selalu balik float (default 0.0) — untuk penjumlahan. */
    public static function amount(mixed $value): float
    {
        return self::parse($value) ?? 0.0;
    }

    /**
     * Pecahkan ambiguitas titik vs koma. Aturan: pemisah TERAKHIR yang muncul
     * dengan 1-2 digit di belakangnya = pemisah desimal; sisanya pemisah ribuan.
     * Tiga digit di belakang (mis. "1.250") selalu ribuan, bukan desimal.
     */
    private static function normalizeSeparators(string $s): ?float
    {
        $lastDot = strrpos($s, '.');
        $lastComma = strrpos($s, ',');
        $lastSep = max($lastDot === false ? -1 : $lastDot, $lastComma === false ? -1 : $lastComma);

        if ($lastSep < 0) {
            return is_numeric($s) ? (float) $s : null;
        }

        $tail = substr($s, $lastSep + 1);
        $decimals = strlen($tail);

        // Ekor 1-2 digit → pemisah desimal. Selain itu semua pemisah = ribuan.
        if ($decimals >= 1 && $decimals <= 2 && ctype_digit($tail)) {
            $head = preg_replace('/[^0-9]/', '', substr($s, 0, $lastSep)) ?? '';

            return (float) (($head === '' ? '0' : $head).'.'.$tail);
        }

        $digits = preg_replace('/[^0-9]/', '', $s) ?? '';

        return $digits === '' ? null : (float) $digits;
    }

    /** 1250000 → "1.250.000" (tanpa desimal kalau bulat). */
    public static function format(float|int|null $value, bool $withDecimals = false): string
    {
        $value = (float) ($value ?? 0);
        $decimals = $withDecimals || abs($value - round($value)) >= 0.005 ? 2 : 0;

        return number_format($value, $decimals, ',', '.');
    }
}
