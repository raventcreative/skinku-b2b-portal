<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tandai asal kartu Kanban. null = dibuat manual user; 'ai' = dibuat Asisten AI.
 * Dipakai buat menampilkan lencana kecil "AI" di kartu agar bisa dibedakan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('board_cards', function (Blueprint $table) {
            $table->string('created_via')->nullable()->after('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('board_cards', function (Blueprint $table) {
            $table->dropColumn('created_via');
        });
    }
};
