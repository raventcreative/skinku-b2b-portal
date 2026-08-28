<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Konten & Views: tipe konten, thumbnail, catatan (di konten) + metrik saves
 * (di snapshot). Melengkapi ER/CPM per konten & auto-deteksi tipe dari URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kol_contents', function (Blueprint $table) {
            $table->string('content_type', 12)->nullable()->after('platform'); // video|reels|story|live|feed|other
            $table->string('thumbnail_url')->nullable()->after('title');
            $table->text('notes')->nullable()->after('thumbnail_url');
        });

        Schema::table('kol_content_snapshots', function (Blueprint $table) {
            $table->unsignedBigInteger('saves')->nullable()->after('shares');
        });
    }

    public function down(): void
    {
        Schema::table('kol_content_snapshots', function (Blueprint $table) {
            $table->dropColumn('saves');
        });
        Schema::table('kol_contents', function (Blueprint $table) {
            $table->dropColumn(['content_type', 'thumbnail_url', 'notes']);
        });
    }
};
