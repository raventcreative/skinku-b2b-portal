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

// Order Shopee tiap 30 menit — sekaligus auto-potong stok kalau saklarnya aktif.
Schedule::command('shopee:sync')->everyThirtyMinutes()->withoutOverlapping(15);

// Retur Shopee cukup sekali sehari (jarang berubah, hemat kuota API).
Schedule::command('shopee:sync --returns')->dailyAt('01:15')->withoutOverlapping(30);

// Escrow/pencairan Shopee sekali sehari (jarang berubah setelah rilis).
Schedule::command('shopee:sync --settlements')->dailyAt('01:30')->withoutOverlapping(30);

// Mutasi saldo (wallet) Shopee sekali sehari.
Schedule::command('shopee:sync --wallet')->dailyAt('01:45')->withoutOverlapping(30);

// Retur & pencairan cukup sekali sehari (jarang berubah, hemat kuota API).
Schedule::command('tiktok:sync --returns --settlements')->dailyAt('01:00')->withoutOverlapping(30);

/*
 * Keterangan pencairan: SATU panggilan API per statement, jadi tak bisa diborong
 * seperti tarikan lain. Dulu ini satu-satunya bagian yang manual — tombolnya
 * dibatasi 60/klik supaya request web tak timeout, dan tumpukannya cuma habis
 * kalau ada yang rajin mengklik. Dijalankan tiap jam: tumpukan digerogoti
 * sendiri, dan berhenti tanpa menyentuh API begitu tak ada tunggakan.
 */
Schedule::command('tiktok:describe')->hourly()->withoutOverlapping(20);

// Sapu penuh sekali sehari: jaring pengaman kalau ada perubahan status yang lolos
// dari jendela update_time (mis. cron sempat mati lama).
Schedule::command('tiktok:sync --full')->dailyAt('03:30')->withoutOverlapping(30);

// Order affiliate (Tim Gapok) tiap 6 jam — 30 hari terakhir. Komisi jarang
// berubah tiap menit; cukup beberapa kali sehari. Lewati sendiri bila belum connect.
Schedule::command('tiktok:affiliate-sync')->everySixHours()->withoutOverlapping(30);

/*
 * Pekerja antrean OKR (generate draf di background). Numpang cron scheduler yang
 * sudah ada — tanpa worker permanen. Tiap menit: proses job yang ada lalu berhenti
 * begitu antrean kosong (--stop-when-empty). --timeout=290 memberi ruang job AI
 * yang lambat (otak cadangan bisa lambat) — jangan pakai default 60 dtk yang bakal
 * membunuh job di tengah. withoutOverlapping mencegah dua worker jalan bersamaan.
 */
Schedule::command('queue:work --stop-when-empty --tries=1 --timeout=290')
    ->everyMinute()->name('okr-queue-worker')->withoutOverlapping(10);
