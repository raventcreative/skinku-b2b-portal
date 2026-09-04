<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jumlah video & LIVE per kreator per bulan (dari TikTok Shop Analytics —
 * Get Shop Video/LIVE Performance List). Diagregat dari list API, disimpan per
 * (kol, bulan) buat kolom Video/LIVE di Tim Gapok. period = string 'Y-m-01'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_creator_content_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kol_id')->constrained('kols')->cascadeOnDelete();
            $table->date('period'); // tanggal 1 bulan ybs
            $table->unsignedInteger('videos')->default(0);
            $table->unsignedInteger('lives')->default(0);
            $table->timestamps();
            $table->unique(['kol_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_creator_content_stats');
    }
};
