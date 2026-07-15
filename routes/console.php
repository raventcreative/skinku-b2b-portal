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

// Backup DB tiap malam — jaring pengaman utama (hapus jurnal / migrasi keliru).
Schedule::command('db:backup')->dailyAt('02:30')->withoutOverlapping();

// Order tiap 30 menit — sekaligus auto-potong stok kalau saklarnya aktif.
Schedule::command('tiktok:sync')->everyThirtyMinutes()->withoutOverlapping();

// Retur & pencairan cukup sekali sehari (jarang berubah, hemat kuota API).
Schedule::command('tiktok:sync --returns --settlements')->dailyAt('01:00')->withoutOverlapping();

// Sapu penuh sekali sehari: jaring pengaman kalau ada perubahan status yang lolos
// dari jendela update_time (mis. cron sempat mati lama).
Schedule::command('tiktok:sync --full')->dailyAt('03:30')->withoutOverlapping();
