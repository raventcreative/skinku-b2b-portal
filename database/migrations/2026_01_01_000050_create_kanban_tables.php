<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kanban ala Trello (papan tugas bebas) — pilihan eksplisit Freddie di antara
 * opsi pipeline-view. Sepenuhnya ADITIF: 3 tabel baru, nol sentuhan ke modul
 * lain, supaya rollback bersih (lihat docs/ROLLBACK-KANBAN.md, tag pre-kanban).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boards', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('board_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('boards')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('board_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('column_id')->constrained('board_columns')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['column_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_cards');
        Schema::dropIfExists('board_columns');
        Schema::dropIfExists('boards');
        // Bersihkan override permission kanban supaya rollback tak meninggalkan
        // baris yatim di role_permissions.
        DB::table('role_permissions')->where('permission_key', 'like', 'kanban%')->delete();
    }
};
