<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laporan Hasil Endorse (Evaluasi Kinerja) per deal — sheet "KOL Overview".
 * Input agregat per deal; CPM/ROMI/verdict dihitung di model (accessor), tidak
 * disimpan (satu sumber kebenaran = input).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kol_deals', function (Blueprint $table) {
            $table->string('hasil_tujuan')->nullable()->after('atas_nama');   // penjualan | awareness
            $table->unsignedInteger('hasil_video_upload')->nullable()->after('hasil_tujuan');
            $table->unsignedInteger('hasil_video_fyp')->nullable()->after('hasil_video_upload');
            $table->unsignedBigInteger('hasil_views')->nullable()->after('hasil_video_fyp');
            $table->unsignedBigInteger('hasil_revenue')->nullable()->after('hasil_views');
            $table->text('hasil_catatan')->nullable()->after('hasil_revenue');
            $table->timestamp('hasil_diisi_at')->nullable()->after('hasil_catatan');
        });
    }

    public function down(): void
    {
        Schema::table('kol_deals', function (Blueprint $table) {
            $table->dropColumn([
                'hasil_tujuan', 'hasil_video_upload', 'hasil_video_fyp',
                'hasil_views', 'hasil_revenue', 'hasil_catatan', 'hasil_diisi_at',
            ]);
        });
    }
};
