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

// Order tiap 30 menit — sekaligus auto-potong stok kalau saklarnya aktif.
Schedule::command('tiktok:sync')->everyThirtyMinutes()->withoutOverlapping();

// Retur & pencairan cukup sekali sehari (jarang berubah, hemat kuota API).
Schedule::command('tiktok:sync --returns --settlements')->dailyAt('01:00')->withoutOverlapping();
