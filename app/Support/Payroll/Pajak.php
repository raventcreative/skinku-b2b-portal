<?php

namespace App\Support\Payroll;

/**
 * Tarif PPh 21 pegawai tetap (murni hitung, tanpa DB) — sumber: PP 58/2023 (TER bulanan, lampiran A/B/C),
 * PMK 168/2023 (tata cara; masa pajak terakhir dihitung ulang setahun), UU 7/2021 HPP Pasal 17 ayat (1) huruf a,
 * PMK 101/2016 (PTKP). Diperiksa 2026-10-10. Bila aturan berubah, ubah konstanta di sini saja.
 * Uang dalam rupiah bulat; tarif dalam basis poin (1% = 100) supaya hitungan tetap bilangan bulat.
 */
final class Pajak
{
    /** Kategori TER menurut status PTKP. */
    public const KATEGORI = [
        'TK/0' => 'A', 'TK/1' => 'A', 'K/0' => 'A',
        'TK/2' => 'B', 'TK/3' => 'B', 'K/1' => 'B', 'K/2' => 'B',
        'K/3' => 'C',
    ];

    /** PTKP setahun: diri sendiri Rp54 jt, kawin +Rp4,5 jt, tiap tanggungan +Rp4,5 jt (maks 3). */
    public const PTKP = [
        'TK/0' => 54_000_000, 'TK/1' => 58_500_000, 'TK/2' => 63_000_000, 'TK/3' => 67_500_000,
        'K/0' => 58_500_000, 'K/1' => 63_000_000, 'K/2' => 67_500_000, 'K/3' => 72_000_000,
    ];

    /** Status PTKP bila belum diisi di data karyawan. */
    public const PTKP_BAWAAN = 'TK/0';

    /**
     * TER bulanan: [batas atas penghasilan bruto sebulan (inklusif), tarif bps]. Baris pertama yang batasnya ≥ bruto
     * berlaku ("di atas X s.d. Y"). Total 125 lapisan (A 44, B 40, C 41).
     */
    public const TER = [
        'A' => [
            [5_400_000, 0], [5_650_000, 25], [5_950_000, 50], [6_300_000, 75], [6_750_000, 100], [7_500_000, 125],
            [8_550_000, 150], [9_650_000, 175], [10_050_000, 200], [10_350_000, 225], [10_700_000, 250],
            [11_050_000, 300], [11_600_000, 350], [12_500_000, 400], [13_750_000, 500], [15_100_000, 600],
            [16_950_000, 700], [19_750_000, 800], [24_150_000, 900], [26_450_000, 1000], [28_000_000, 1100],
            [30_050_000, 1200], [32_400_000, 1300], [35_400_000, 1400], [39_100_000, 1500], [43_850_000, 1600],
            [47_800_000, 1700], [51_400_000, 1800], [56_300_000, 1900], [62_200_000, 2000], [68_600_000, 2100],
            [77_500_000, 2200], [89_000_000, 2300], [103_000_000, 2400], [125_000_000, 2500], [157_000_000, 2600],
            [206_000_000, 2700], [337_000_000, 2800], [454_000_000, 2900], [550_000_000, 3000], [695_000_000, 3100],
            [910_000_000, 3200], [1_400_000_000, 3300], [PHP_INT_MAX, 3400],
        ],
        'B' => [
            [6_200_000, 0], [6_500_000, 25], [6_850_000, 50], [7_300_000, 75], [9_200_000, 100], [10_750_000, 150],
            [11_250_000, 200], [11_600_000, 250], [12_600_000, 300], [13_600_000, 400], [14_950_000, 500],
            [16_400_000, 600], [18_450_000, 700], [21_850_000, 800], [26_000_000, 900], [27_700_000, 1000],
            [29_350_000, 1100], [31_450_000, 1200], [33_950_000, 1300], [37_100_000, 1400], [41_100_000, 1500],
            [45_800_000, 1600], [49_500_000, 1700], [53_800_000, 1800], [58_500_000, 1900], [64_000_000, 2000],
            [71_000_000, 2100], [80_000_000, 2200], [93_000_000, 2300], [109_000_000, 2400], [129_000_000, 2500],
            [163_000_000, 2600], [211_000_000, 2700], [374_000_000, 2800], [459_000_000, 2900], [555_000_000, 3000],
            [704_000_000, 3100], [957_000_000, 3200], [1_405_000_000, 3300], [PHP_INT_MAX, 3400],
        ],
        'C' => [
            [6_600_000, 0], [6_950_000, 25], [7_350_000, 50], [7_800_000, 75], [8_850_000, 100], [9_800_000, 125],
            [10_950_000, 150], [11_200_000, 175], [12_050_000, 200], [12_950_000, 300], [14_150_000, 400],
            [15_550_000, 500], [17_050_000, 600], [19_500_000, 700], [22_700_000, 800], [26_600_000, 900],
            [28_100_000, 1000], [30_100_000, 1100], [32_600_000, 1200], [35_400_000, 1300], [38_900_000, 1400],
            [43_000_000, 1500], [47_400_000, 1600], [51_200_000, 1700], [55_800_000, 1800], [60_400_000, 1900],
            [66_700_000, 2000], [74_500_000, 2100], [83_200_000, 2200], [95_600_000, 2300], [110_000_000, 2400],
            [134_000_000, 2500], [169_000_000, 2600], [221_000_000, 2700], [390_000_000, 2800], [463_000_000, 2900],
            [561_000_000, 3000], [709_000_000, 3100], [965_000_000, 3200], [1_419_000_000, 3300], [PHP_INT_MAX, 3400],
        ],
    ];

    /** Tarif Pasal 17 ayat (1) huruf a: [batas atas PKP setahun, tarif bps]. */
    public const PASAL_17 = [
        [60_000_000, 500], [250_000_000, 1500], [500_000_000, 2500], [5_000_000_000, 3000], [PHP_INT_MAX, 3500],
    ];

    /** Biaya jabatan: 5% bruto, maks Rp500.000 sebulan / Rp6.000.000 setahun. */
    public const BIAYA_JABATAN_BULAN = 500_000;

    public static function ptkpSah(?string $status): string
    {
        return isset(self::PTKP[$status]) ? $status : self::PTKP_BAWAAN;
    }

    public static function kategori(?string $ptkp): string
    {
        return self::KATEGORI[self::ptkpSah($ptkp)];
    }

    /** Tarif TER (bps) untuk penghasilan bruto sebulan. */
    public static function ter(string $kategori, int $bruto): int
    {
        foreach (self::TER[$kategori] as [$batas, $bps]) {
            if ($bruto <= $batas) {
                return $bps;
            }
        }

        return 3400;
    }

    /** PPh 21 setahun atas PKP (sudah dibulatkan ke bawah ribuan) dengan tarif progresif Pasal 17. */
    public static function pasal17(int $pkp): int
    {
        $pajak = 0;
        $bawah = 0;
        foreach (self::PASAL_17 as [$batas, $bps]) {
            if ($pkp <= $bawah) {
                break;
            }
            $pajak += intdiv((min($pkp, $batas) - $bawah) * $bps, 10_000);
            $bawah = $batas;
        }

        return $pajak;
    }

    /** Biaya jabatan setahun: 5% bruto, maks Rp500.000 × jumlah bulan bekerja (≤ 12 → maks Rp6 jt). */
    public static function biayaJabatan(int $brutoSetahun, int $bulan): int
    {
        return min(intdiv($brutoSetahun * 5, 100), self::BIAYA_JABATAN_BULAN * max(1, min(12, $bulan)));
    }
}
