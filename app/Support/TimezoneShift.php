<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Geser semua instan waktu di database saat zona waktu aplikasi berubah.
 *
 * Latar: aplikasi awalnya berjalan di UTC, padahal bisnisnya WIB. Semua waktu
 * tersimpan sebagai UTC. Saat app.timezone pindah ke Asia/Jakarta (+7), nilai
 * lama akan DIBACA sebagai WIB — sehingga tampak 7 jam lebih awal dari kejadian
 * sebenarnya. Menggesernya +7 jam membuat setiap nilai tetap menunjuk momen
 * dunia nyata yang sama.
 *
 * Yang TIDAK digeser:
 *  - Kolom DATE (mis. acc_journals.date, produced_at, deduct_from) — itu tanggal
 *    jam-dinding yang diketik manusia, bukan instan. Menggesernya malah merusak.
 *  - Tabel infrastruktur (sesi, cache, antrean, token reset) — fana, dan menggeser
 *    token reset justru MEMPERPANJANG masa berlakunya.
 */
class TimezoneShift
{
    /** Tabel yang isinya fana / bukan data bisnis. */
    private const SKIP_TABLES = [
        'migrations', 'sessions', 'cache', 'cache_locks',
        'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens',
    ];

    /**
     * Kolom datetime/timestamp per tabel — dibaca dari skema, bukan daftar tetap,
     * supaya tidak ada kolom yang terlewat.
     *
     * @return array<string, array<int, string>>
     */
    public static function columns(): array
    {
        $out = [];
        // Introspeksi lewat Schema (bukan information_schema) supaya jalan di MySQL
        // (produksi) maupun SQLite (test) — logikanya jadi bisa diuji beneran.
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if (in_array($name, self::SKIP_TABLES, true)) {
                continue;
            }
            foreach (Schema::getColumns($name) as $col) {
                // Sengaja HANYA datetime & timestamp. 'date' dikecualikan.
                if (in_array(strtolower($col['type_name']), ['datetime', 'timestamp'], true)) {
                    $out[$name][] = $col['name'];
                }
            }
        }

        return $out;
    }

    /**
     * Geser semua kolom instan sebanyak $hours (boleh negatif).
     * DATE_ADD(NULL) = NULL, jadi nilai kosong tetap kosong.
     *
     * @return array{tables:int, columns:int}
     */
    public static function shift(int $hours): array
    {
        $map = self::columns();
        $sqlite = DB::connection()->getDriverName() === 'sqlite';

        // Semua-atau-tidak-sama-sekali: kalau gagal di tengah, sebagian tabel
        // tergeser dan sebagian tidak — itu jauh lebih buruk daripada gagal total.
        // Aman dibungkus transaksi karena ini murni UPDATE (tanpa DDL).
        DB::transaction(function () use ($map, $sqlite, $hours) {
            foreach ($map as $table => $cols) {
                // Semua kolom satu tabel dalam SATU statement — lebih cepat, dan tak
                // memicu pembaruan berulang pada baris yang sama.
                $sets = implode(', ', array_map(
                    fn ($c) => $sqlite
                        ? "`{$c}` = datetime(`{$c}`, '{$hours} hours')"
                        : "`{$c}` = DATE_ADD(`{$c}`, INTERVAL {$hours} HOUR)",
                    $cols,
                ));
                DB::statement("UPDATE `{$table}` SET {$sets}");
            }
        });

        return ['tables' => count($map), 'columns' => array_sum(array_map('count', $map))];
    }
}
