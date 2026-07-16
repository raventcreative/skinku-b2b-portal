<?php

use App\Support\TimezoneShift;
use Illuminate\Database\Migrations\Migration;

/**
 * Aplikasi pindah dari UTC ke Asia/Jakarta (+7). Data lama tersimpan sebagai UTC,
 * jadi harus digeser +7 jam agar tetap menunjuk momen dunia nyata yang sama.
 *
 * WAJIB jadi migrasi TERAKHIR: ia membaca skema saat itu, jadi semua kolom waktu
 * dari migrasi sebelumnya sudah ada dan ikut tergeser.
 *
 * Pada database baru (tabel kosong) migrasi ini tidak berdampak apa-apa.
 */
return new class extends Migration
{
    public function up(): void
    {
        TimezoneShift::shift(7);
    }

    public function down(): void
    {
        TimezoneShift::shift(-7);
    }
};
