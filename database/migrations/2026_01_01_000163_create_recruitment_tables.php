<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu HR Fase 2 — Rekrutmen + Psikotes (spec docs/superpowers/specs/2026-10-10-hr-design.md): lowongan, kandidat,
 * sesi psikotes (link publik bertoken, jawaban & skor JSON).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_openings', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('department', 60)->nullable();
            $table->string('status', 10)->default('buka');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_opening_id')->nullable()->constrained('job_openings')->nullOnDelete();
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('source', 60)->nullable();          // Instagram, JobStreet, referensi, …
            $table->string('stage', 20)->default('lamar');     // lamar → psikotes → interview → diterima / ditolak
            $table->dateTime('interview_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['stage', 'job_opening_id']);
        });

        Schema::create('psychotest_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->json('tests');
            $table->dateTime('expires_at');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->json('progress')->nullable();
            $table->json('answers')->nullable();
            $table->json('results')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('psychotest_sessions');
        Schema::dropIfExists('candidates');
        Schema::dropIfExists('job_openings');
    }
};
