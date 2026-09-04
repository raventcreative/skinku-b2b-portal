<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gaji pokok bulanan per anggota Tim Affiliate Gapok. Satu baris per (kol, bulan)
 * — gaji beda tiap orang & bisa berubah tiap bulan (riwayat). period = tanggal 1
 * bulan ybs (YYYY-MM-01). Dipakai hitung ROI (GMV/komisi ÷ gaji) di halaman gapok.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_gapok_salaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kol_id')->constrained('kols')->cascadeOnDelete();
            $table->date('period'); // tanggal 1 tiap bulan
            $table->unsignedBigInteger('monthly_salary')->default(0);
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['kol_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_gapok_salaries');
    }
};
