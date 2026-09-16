<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kols', function (Blueprint $table) {
            // Tanggal anggota bergabung ke Tim Gapok (bisa di-backfill manual).
            $table->date('gapok_joined_at')->nullable()->after('is_gapok');
        });
    }

    public function down(): void
    {
        Schema::table('kols', function (Blueprint $table) {
            $table->dropColumn('gapok_joined_at');
        });
    }
};
