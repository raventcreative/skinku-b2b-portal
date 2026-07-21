<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform sosial media tiap KOL, supaya username bisa ditautkan ke profil yang
 * benar (TikTok/Instagram/YouTube/…). Default 'tiktok' — seluruh data lama
 * memang KOL TikTok (kolom & modul ini lahir TikTok-sentris), jadi tak ada baris
 * yang salah tautan setelah migrasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kols', function (Blueprint $table) {
            $table->string('platform', 20)->default('tiktok')->after('tiktok_username');
        });
    }

    public function down(): void
    {
        Schema::table('kols', function (Blueprint $table) {
            $table->dropColumn('platform');
        });
    }
};
