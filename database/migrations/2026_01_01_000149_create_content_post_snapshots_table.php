<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portal Content Creator Fase 3 — snapshot metrik harian per target terbit (FR-80).
 * Pola sama dengan kol_content_snapshots: satu baris per target per hari.
 * Metrik nullable karena tiap platform menyediakan metrik berbeda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_post_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_post_target_id')->constrained('content_post_targets')->cascadeOnDelete();
            $table->date('captured_on');
            $table->unsignedBigInteger('views')->nullable();
            $table->unsignedBigInteger('reach')->nullable();
            $table->unsignedBigInteger('likes')->nullable();
            $table->unsignedBigInteger('comments')->nullable();
            $table->unsignedBigInteger('shares')->nullable();
            $table->unsignedBigInteger('saves')->nullable();
            $table->timestamps();
            $table->unique(['content_post_target_id', 'captured_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_post_snapshots');
    }
};
