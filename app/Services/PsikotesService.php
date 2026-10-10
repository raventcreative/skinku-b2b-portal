<?php

namespace App\Services;

use App\Support\Psikotes\BankSoal;

/**
 * Skor psikotes rekrutmen (bank soal: App\Support\Psikotes\BankSoal). Murni hitung — tanpa DB — supaya mudah diuji.
 * Hasil = info pendukung wawancara, bukan diagnosis.
 */
class PsikotesService
{
    /** Urutan tes dalam satu sesi. */
    public const TES = [
        'kepribadian' => 'Kepribadian 16 tipe',
        'disc' => 'Gaya kerja (DISC)',
        'logika' => 'Logika & hitung',
    ];

    /**
     * @param  array<int,int|string>  $jawaban  indeks pernyataan => 1..5
     * @return array{tipe:string,deskripsi:string,dimensi:array<string,array<string,int>>}
     */
    public function skorKepribadian(array $jawaban): array
    {
        $net = $maks = ['EI' => 0, 'SN' => 0, 'TF' => 0, 'JP' => 0];
        foreach (BankSoal::KEPRIBADIAN as $i => [, $dim, $kutub]) {
            $nilai = max(1, min(5, (int) ($jawaban[$i] ?? 3))) - 3;   // −2 … +2
            $net[$dim] += $kutub === $dim[0] ? $nilai : -$nilai;      // + = huruf pertama (E/S/T/J)
            $maks[$dim] += 2;
        }

        $tipe = '';
        $dimensi = [];
        foreach ($net as $dim => $n) {
            $pertama = (int) round(50 + ($maks[$dim] ? $n / $maks[$dim] * 50 : 0));
            $tipe .= $n >= 0 ? $dim[0] : $dim[1];                      // seri → huruf pertama
            $dimensi[$dim] = [$dim[0] => $pertama, $dim[1] => 100 - $pertama];
        }

        return ['tipe' => $tipe, 'deskripsi' => BankSoal::TIPE[$tipe], 'dimensi' => $dimensi];
    }

    /**
     * @param  array<int,string>  $paling  indeks kelompok => huruf D/I/S/C yang PALING menggambarkan
     * @param  array<int,string>  $kurang  indeks kelompok => huruf yang PALING TIDAK menggambarkan
     * @return array{skor:array<string,int>,utama:string,kedua:string,deskripsi:string}
     */
    public function skorDisc(array $paling, array $kurang): array
    {
        $skor = ['D' => 0, 'I' => 0, 'S' => 0, 'C' => 0];
        foreach (array_keys(BankSoal::DISC) as $g) {
            if (isset($skor[$paling[$g] ?? ''])) {
                $skor[$paling[$g]]++;
            }
            if (isset($skor[$kurang[$g] ?? ''])) {
                $skor[$kurang[$g]]--;
            }
        }
        $urut = $skor;
        arsort($urut); // stabil: seri tetap urutan D, I, S, C
        [$utama, $kedua] = array_keys($urut);

        return ['skor' => $skor, 'utama' => $utama, 'kedua' => $kedua, 'deskripsi' => BankSoal::GAYA_DISC[$utama]];
    }

    /**
     * @param  array<int,int|string>  $jawaban  indeks soal => indeks pilihan (yang kosong = salah)
     * @return array{benar:int,total:int,skor:int,kategori:string}
     */
    public function skorLogika(array $jawaban): array
    {
        $benar = 0;
        foreach (BankSoal::LOGIKA as $i => [, , $kunci]) {
            if (isset($jawaban[$i]) && (string) $jawaban[$i] === (string) $kunci) {
                $benar++;
            }
        }
        $total = count(BankSoal::LOGIKA);
        $skor = (int) round($benar / $total * 100);

        return ['benar' => $benar, 'total' => $total, 'skor' => $skor, 'kategori' => match (true) {
            $skor >= 80 => 'Sangat baik',
            $skor >= 60 => 'Baik',
            $skor >= 40 => 'Cukup',
            default => 'Perlu latihan',
        }];
    }

    /**
     * Urutan tampil kata DISC per kelompok (diputar sesuai nomor kelompok) → huruf tak selalu di posisi yang sama.
     *
     * @return array<int,array{huruf:string,kata:string}>
     */
    public function urutanDisc(int $g): array
    {
        $kata = array_map(null, ['D', 'I', 'S', 'C'], BankSoal::DISC[$g]);
        $geser = $g % 4;
        $kata = [...array_slice($kata, $geser), ...array_slice($kata, 0, $geser)];

        return array_map(fn ($k) => ['huruf' => $k[0], 'kata' => $k[1]], $kata);
    }
}
