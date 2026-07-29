<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OKR buatan AI: rencana disimpan sebagai draf dulu, baru kartu Kanban dibuat
 * setelah manusia menyetujui pratinjau. Relasi tugas -> kartu menjadi sumber
 * progres tunggal, jadi tidak ada persentase yang perlu diisi manual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('okr_cycles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('period_type', 20);
            $table->string('period_label', 50);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('scope_type', 20);
            $table->string('scope_name')->nullable();
            $table->foreignId('scope_owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('direction');
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'start_date', 'end_date']);
        });

        Schema::create('okr_objectives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('okr_cycle_id')->constrained('okr_cycles')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('okr_key_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('okr_objective_id')->constrained('okr_objectives')->cascadeOnDelete();
            $table->string('title');
            $table->string('metric')->nullable();
            $table->string('target')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('okr_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('okr_key_result_id')->constrained('okr_key_results')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('board_column_id')->nullable()->constrained('board_columns')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('board_card_id')->nullable()->unique()->constrained('board_cards')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('okr_tasks');
        Schema::dropIfExists('okr_key_results');
        Schema::dropIfExists('okr_objectives');
        Schema::dropIfExists('okr_cycles');

        DB::table('role_permissions')->where('permission_key', 'like', 'okr.%')->delete();
    }
};
