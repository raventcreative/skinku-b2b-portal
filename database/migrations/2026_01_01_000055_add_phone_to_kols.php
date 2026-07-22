<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * No. HP KOL — kontak untuk dealing. Nullable (banyak KOL lama belum ada
 * nomornya). Aditif, tak menyentuh data lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kols', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('agency');
        });
    }

    public function down(): void
    {
        Schema::table('kols', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
