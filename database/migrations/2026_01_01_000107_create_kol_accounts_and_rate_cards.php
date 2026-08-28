<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-akun platform (additive): akun UTAMA tetap di kols (tak menyentuh
 * screening/affiliate/pipeline/deal); akun tambahan di kol_accounts.
 * + Rate card per tipe konten (video/reels/story/live/…) dengan riwayat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kol_id')->constrained('kols')->cascadeOnDelete();
            $table->string('platform', 20);
            $table->string('username');
            $table->unsignedBigInteger('followers')->nullable();
            $table->string('profile_link')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('kol_id');
        });

        Schema::create('kol_rate_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kol_id')->constrained('kols')->cascadeOnDelete();
            $table->string('content_type', 20); // tiktok_video | reels | story | live | bundle | other
            $table->unsignedBigInteger('rate');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index('kol_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_rate_cards');
        Schema::dropIfExists('kol_accounts');
    }
};
