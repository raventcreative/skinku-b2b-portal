<?php

namespace App\Support;

/**
 * Harga Rupiah bertitik ribuan untuk kolom input form (zero-dependency). Rupiah tanpa sen.
 * Dipasangkan dengan partials/rupiah-input (format saat diketik + kirim angka polos).
 */
class Rupiah
{
    /** Nilai awal kolom harga: 195000 / "195000.00" → "195.000"; null/''/bukan angka → ''. */
    public static function input(mixed $v): string
    {
        if ($v === null || $v === '' || ! is_numeric($v)) {
            return '';
        }

        return number_format((float) $v, 0, ',', '.');
    }

    /**
     * Jaring pengaman server: "195.000" / "Rp 1.500.000" → "195000". Wajib dipanggil SEBELUM validasi —
     * tanpa ini "65.000" lolos `numeric` sebagai 65 (seribu kali lebih kecil, tanpa error). Selain pola
     * bertitik ribuan (mis. "195000", "195000.00", "12.50") dikembalikan apa adanya.
     */
    public static function polos(mixed $v): mixed
    {
        if (is_string($v) && preg_match('/^\s*(?:Rp\.?\s*)?(\d{1,3}(?:\.\d{3})+)\s*$/i', $v, $m)) {
            return str_replace('.', '', $m[1]);
        }

        return $v;
    }
}
