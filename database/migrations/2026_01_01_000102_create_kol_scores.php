<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak historis skor KOL. Dulu APS & KSS selalu dihitung ulang tiap buka, tak
 * ada rekam jejak. Tabel ini menyimpan snapshot skor: type=aps (potensi
 * affiliate, direkam harian) / type=kss (seleksi KOL, saat kalkulator dipakai).
 * Idempoten harian: unique (kol_id, type, captured_on).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kol_id')->constrained('kols')->cascadeOnDelete();
            $table->string('type', 8);              // aps | kss
            $table->decimal('score', 5, 1)->nullable();
            $table->string('label', 32)->nullable(); // aps: bina_intensif… / kss: shortlist…
            $table->json('meta')->nullable();        // input/breakdown ringkas
            $table->date('captured_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['kol_id', 'type', 'captured_on']);
            $table->index(['kol_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_scores');
    }
};
