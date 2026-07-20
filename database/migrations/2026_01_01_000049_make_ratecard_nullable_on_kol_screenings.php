<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ratecard jadi opsional (permintaan Freddie, deviasi dari Excel yang
 * menandainya "Wajib diisi").
 *
 * Alur nyata: views KOL bisa discreening dari TikTok publik SEBELUM kontak —
 * harga baru ada setelah nego. Ratecard wajib berarti kandidat tanpa harga tak
 * bisa dicatat sama sekali, padahal median/ratio/GMV/viral/fake tidak butuh
 * harga. Yang menunggu ratecard hanya CPM/CPV/verdict/rank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kol_screenings', function (Blueprint $table) {
            $table->unsignedBigInteger('ratecard')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Baris tanpa harga diberi 0 dulu supaya NOT NULL bisa dipasang lagi.
        DB::table('kol_screenings')->whereNull('ratecard')->update(['ratecard' => 0]);
        Schema::table('kol_screenings', function (Blueprint $table) {
            $table->unsignedBigInteger('ratecard')->nullable(false)->change();
        });
    }
};
