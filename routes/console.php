<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Jadwal otomatis (butuh 1 cron di server: * * * * * php artisan schedule:run)
|--------------------------------------------------------------------------
*/

/*
 * PENTING soal withoutOverlapping(N): argumennya = umur kunci dalam MENIT.
 * Tanpa argumen, Laravel memakai 24 JAM. Di shared hosting proses gampang
 * dibunuh di tengah jalan (limit CPU/memori) → kunci tak pernah dilepas →
 * SEMUA run berikutnya dilewati diam-diam sampai 24 jam. Selalu beri batas
 * yang sedikit lebih lama dari durasi wajar tugasnya, jangan biarkan default.
 */

/*
 * Detak jantung penjadwal. Tanpa ini, "sinkron basi" ambigu: cron-nya yang mati,
 * atau cron jalan tapi tugasnya gagal? Ini memisahkan dua kemungkinan itu.
 */
Schedule::call(fn () => cache()->put('scheduler_heartbeat', now()->toDateTimeString(), now()->addDays(7)))
    ->everyFiveMinutes()->name('scheduler-heartbeat')->withoutOverlapping(5);

// Backup DB tiap malam — jaring pengaman utama (hapus jurnal / migrasi keliru).
Schedule::command('db:backup')->dailyAt('02:30')->withoutOverlapping(30);

// Order tiap 30 menit — sekaligus auto-potong stok kalau saklarnya aktif.
Schedule::command('tiktok:sync')->everyThirtyMinutes()->withoutOverlapping(15);

// Retur & pencairan cukup sekali sehari (jarang berubah, hemat kuota API).
Schedule::command('tiktok:sync --returns --settlements')->dailyAt('01:00')->withoutOverlapping(30);

// Sapu penuh sekali sehari: jaring pengaman kalau ada perubahan status yang lolos
// dari jendela update_time (mis. cron sempat mati lama).
Schedule::command('tiktok:sync --full')->dailyAt('03:30')->withoutOverlapping(30);
