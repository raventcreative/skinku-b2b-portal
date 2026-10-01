<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performa 30 hari dari TikTok Creator Marketplace (endpoint performa kreator):
 * engagement, GPM, jumlah video/LIVE, dst. Profil = angka TERBARU; tabel
 * snapshot = riwayat per tanggal sync untuk grafik tracker di Detail KOL.
 * Semua uang disimpan Rupiah (USD × kurs saat sync).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kol_tiktok_profiles', function (Blueprint $table) {
            $table->unsignedInteger('video_count')->nullable()->after('avg_live_uv');
            $table->unsignedInteger('live_count')->nullable()->after('video_count');
            $table->decimal('video_engagement_pct', 7, 2)->nullable()->after('live_count');
            $table->decimal('live_engagement_pct', 7, 2)->nullable()->after('video_engagement_pct');
            $table->unsignedBigInteger('gpm_idr')->nullable()->after('live_engagement_pct');   // GMV per 1.000 views
            $table->unsignedInteger('units_sold')->nullable()->after('gpm_idr');
            $table->unsignedInteger('brand_collab_count')->nullable()->after('units_sold');
            $table->decimal('avg_commission_pct', 5, 2)->nullable()->after('brand_collab_count');
            $table->dateTime('performance_synced_at')->nullable()->after('synced_at');
        });

        Schema::create('kol_tiktok_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kol_id')->constrained()->cascadeOnDelete();
            $table->date('captured_on');
            $table->unsignedBigInteger('followers')->nullable();
            $table->unsignedInteger('avg_video_views')->nullable();
            $table->unsignedInteger('video_count')->nullable();
            $table->unsignedInteger('live_count')->nullable();
            $table->decimal('video_engagement_pct', 7, 2)->nullable();
            $table->decimal('live_engagement_pct', 7, 2)->nullable();
            $table->unsignedBigInteger('gmv_idr')->nullable();
            $table->unsignedBigInteger('video_gmv_idr')->nullable();
            $table->unsignedBigInteger('live_gmv_idr')->nullable();
            $table->unsignedBigInteger('gpm_idr')->nullable();
            $table->unsignedInteger('units_sold')->nullable();
            $table->timestamps();
            $table->unique(['kol_id', 'captured_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_tiktok_snapshots');
        Schema::table('kol_tiktok_profiles', function (Blueprint $table) {
            $table->dropColumn(['video_count', 'live_count', 'video_engagement_pct', 'live_engagement_pct',
                'gpm_idr', 'units_sold', 'brand_collab_count', 'avg_commission_pct', 'performance_synced_at']);
        });
    }
};
