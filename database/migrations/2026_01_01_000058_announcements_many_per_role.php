<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengumuman: dari 1-per-role → BANYAK per role. Cukup lepas batasan unik pada
 * role + tambah sort_order untuk urutan tampil. Struktur item (catatan + banner)
 * tak berubah; data lama otomatis tetap (jadi item pertama role-nya).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropUnique(['role']);
            $table->integer('sort_order')->default(0)->after('role');
            $table->index('role');   // masih sering di-query per role di dashboard
        });
    }

    public function down(): void
    {
        // Catatan: balik ke unik butuh ≤1 baris per role dulu.
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn('sort_order');
            $table->unique('role');
        });
    }
};
