<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Prioritas kartu kanban (rendah/normal/penting/urgent) — tampil sebagai label di muka kartu.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('board_cards', function (Blueprint $table) {
            $table->string('priority', 10)->default('normal')->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('board_cards', function (Blueprint $table) {
            $table->dropColumn('priority');
        });
    }
};
