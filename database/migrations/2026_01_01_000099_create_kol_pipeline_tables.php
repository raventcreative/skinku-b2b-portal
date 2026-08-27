<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 sub-menu KOL: pipeline scouting (kanban) — kartu per KOL + log
 * perpindahan stage (append-only). track disiapkan utk 'affiliate' fase depan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_pipeline_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kol_id')->constrained('kols')->cascadeOnDelete();
            $table->string('track', 20)->default('kol');
            $table->string('stage', 30);
            $table->string('next_action')->nullable();
            $table->date('next_action_at')->nullable();
            $table->unsignedTinyInteger('followup_count')->default(0);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['kol_id', 'track']);
            $table->index('stage');
            $table->index('next_action_at');
        });

        Schema::create('kol_pipeline_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained('kol_pipeline_cards')->cascadeOnDelete();
            $table->string('from_stage', 30)->nullable();
            $table->string('to_stage', 30);
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_pipeline_events');
        Schema::dropIfExists('kol_pipeline_cards');
    }
};
