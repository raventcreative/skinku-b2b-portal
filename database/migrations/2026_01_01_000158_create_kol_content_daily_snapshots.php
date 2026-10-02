<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Potret harian performa video SKINKU per kreator (dari sync konten affiliate
 * 04:00). Angka = kumulatif bulan berjalan (MTD) per video saat diambil; views
 * HARIAN = selisih potret hari ini − potret sebelumnya (lihat KolViewsHarianService).
 * kol_creator_contents tetap "angka terbaru" (ditimpa); tabel ini riwayatnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_content_daily_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kol_id')->constrained()->cascadeOnDelete();
            $table->string('content_id', 64);
            $table->string('title', 255)->nullable();
            $table->date('period');          // bulan data (awal bulan) — angka kumulatif dalam bulan ini
            $table->date('captured_on');     // tanggal potret diambil
            $table->dateTime('posted_at')->nullable();
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('gmv')->default(0);
            $table->unsignedInteger('items_sold')->default(0);
            $table->timestamps();
            $table->unique(['content_id', 'period', 'captured_on']);
            $table->index(['kol_id', 'captured_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_content_daily_snapshots');
    }
};
