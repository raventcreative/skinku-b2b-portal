<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TikTok kasih masa berlaku token jauh ke depan (refresh bisa s/d tahun 2125).
 * Kolom TIMESTAMP MySQL cuma sampai 2038 → overflow. Ganti ke DATETIME (s/d 9999).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiktok_connections', function (Blueprint $table) {
            $table->dateTime('access_expires_at')->nullable()->change();
            $table->dateTime('refresh_expires_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tiktok_connections', function (Blueprint $table) {
            $table->timestamp('access_expires_at')->nullable()->change();
            $table->timestamp('refresh_expires_at')->nullable()->change();
        });
    }
};
