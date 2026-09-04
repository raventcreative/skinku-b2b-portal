<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot performa TikTok Creator Marketplace per KOL (1:1) — disimpan dari
 * halaman "Cek Performa TikTok" saat screening. GMV asli TikTok, split
 * video/LIVE, follower, rata-rata views, + demografi audiens. Angka Rupiah
 * hasil konversi USD×kurs saat simpan (usd_idr_rate direkam buat jejak).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_tiktok_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kol_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('open_id', 120)->nullable();
            $table->unsignedBigInteger('followers')->default(0);
            $table->decimal('gmv_usd', 18, 6)->nullable();     // GMV 30 hari (USD, dari TikTok)
            $table->unsignedBigInteger('gmv_idr')->nullable();  // = gmv_usd × kurs
            $table->string('gmv_range', 40)->nullable();        // label bucket TikTok ("Rp1JT+")
            $table->unsignedBigInteger('video_gmv_idr')->nullable();
            $table->unsignedBigInteger('live_gmv_idr')->nullable();
            $table->unsignedInteger('avg_video_views')->default(0);
            $table->unsignedInteger('avg_live_uv')->default(0);
            $table->string('region', 10)->nullable();
            $table->string('gender', 16)->nullable();           // FEMALE | MALE
            $table->decimal('gender_pct', 5, 1)->nullable();    // % gender mayoritas
            $table->string('age_ranges', 120)->nullable();      // "25–34, 18–24"
            $table->unsignedInteger('usd_idr_rate')->nullable();
            $table->dateTime('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_tiktok_profiles');
    }
};
