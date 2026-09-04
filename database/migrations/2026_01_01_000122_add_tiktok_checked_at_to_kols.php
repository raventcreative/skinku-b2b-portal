<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda kapan terakhir KOL dicek ke TikTok Creator Marketplace (sync massal).
 * Diisi tiap percobaan — cocok MAUPUN tak cocok — supaya sync berikutnya bisa
 * melewati yang sudah dicek (resumable) & tak boros rate limit ngulang yang
 * username-nya memang tak ada di marketplace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kols', function (Blueprint $table) {
            $table->dateTime('tiktok_checked_at')->nullable()->after('followers');
        });
    }

    public function down(): void
    {
        Schema::table('kols', function (Blueprint $table) {
            $table->dropColumn('tiktok_checked_at');
        });
    }
};
