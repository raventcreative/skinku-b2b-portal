<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catat KAPAN kartu Kanban selesai (masuk kolom "Done/Selesai"). Dipakai KPI
 * per anggota: ukur selesai vs berjalan & telat vs tepat waktu secara akurat.
 * Diisi/dibersihkan otomatis oleh event model BoardCard saat pindah kolom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('board_cards', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('due_date');
        });
    }

    public function down(): void
    {
        Schema::table('board_cards', function (Blueprint $table) {
            $table->dropColumn('completed_at');
        });
    }
};
