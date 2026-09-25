<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Opsi posting per platform yang dipilih reviewer (TikTok: privacy, izin komentar/duet/stitch). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_post_targets', function (Blueprint $table) {
            $table->json('options')->nullable()->after('caption_override');
        });
    }

    public function down(): void
    {
        Schema::table('content_post_targets', function (Blueprint $table) {
            $table->dropColumn('options');
        });
    }
};
