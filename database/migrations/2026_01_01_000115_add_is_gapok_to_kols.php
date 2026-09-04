<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda anggota "Tim Affiliate Gapok" (affiliate yang digaji pokok) di kols.
 * Additive, default false. Roster gapok = kols where is_gapok. Gaji per bulan
 * disimpan terpisah di kol_gapok_salaries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kols', function (Blueprint $table) {
            $table->boolean('is_gapok')->default(false)->after('role');
            $table->index('is_gapok');
        });
    }

    public function down(): void
    {
        Schema::table('kols', function (Blueprint $table) {
            $table->dropIndex(['is_gapok']);
            $table->dropColumn('is_gapok');
        });
    }
};
