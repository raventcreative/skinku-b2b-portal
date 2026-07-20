<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Screening/kurasi KOL: ratecard + views 7 video terakhir.
 *
 * Semua angka turunan (total, rata, median, CPM, ratio, verdict) SENGAJA tidak
 * jadi kolom — accessor di model. Kalau disimpan, mengubah ambang verdict di
 * config akan membuat data lama menampilkan verdict basi.
 *
 * Satu KOL boleh punya banyak screening (histori kurasi dari waktu ke waktu).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_screenings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kol_id')->constrained('kols')->cascadeOnDelete();
            $table->date('tanggal_listing');
            $table->unsignedBigInteger('ratecard');   // rupiah utuh, tanpa desimal
            for ($i = 1; $i <= 7; $i++) {
                $table->unsignedInteger("views_{$i}")->default(0);
            }
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['kol_id', 'tanggal_listing']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_screenings');
    }
};
