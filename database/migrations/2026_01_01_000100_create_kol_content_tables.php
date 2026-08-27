<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 sub-menu KOL: arsip konten per KOL + snapshot views bertanggal
 * (append-only; satu baris per konten per hari). Kolom source disiapkan utk
 * agen scraper fase depan (manual|agent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kol_id')->constrained('kols')->cascadeOnDelete();
            $table->foreignId('kol_deal_id')->nullable()->constrained('kol_deals')->nullOnDelete();
            $table->string('platform', 20)->default('tiktok');
            $table->string('url');
            $table->string('title')->nullable();
            $table->string('label', 10)->default('earned'); // paid | earned
            $table->date('posted_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('kol_id');
            $table->index('posted_at');
        });

        Schema::create('kol_content_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kol_content_id')->constrained('kol_contents')->cascadeOnDelete();
            $table->unsignedBigInteger('views');
            $table->unsignedBigInteger('likes')->nullable();
            $table->unsignedBigInteger('comments')->nullable();
            $table->unsignedBigInteger('shares')->nullable();
            $table->date('captured_on');
            $table->string('source', 10)->default('manual'); // manual | agent
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['kol_content_id', 'captured_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_content_snapshots');
        Schema::dropIfExists('kol_contents');
    }
};
