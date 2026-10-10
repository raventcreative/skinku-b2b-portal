<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ringkasan CV kandidat (poin pengalaman kerja, pendidikan, keahlian, dll.) — diisi "Baca CV dengan AI" atau manual,
 * terpisah dari Catatan HR. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->text('cv_summary')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropColumn('cv_summary');
        });
    }
};
