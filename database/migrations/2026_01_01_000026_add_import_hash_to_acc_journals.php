<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sidik jari baris mutasi bank untuk cegah impor dobel (idempotent). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_journals', function (Blueprint $table) {
            $table->string('import_hash', 64)->nullable()->after('source_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('acc_journals', function (Blueprint $table) {
            $table->dropIndex(['import_hash']);
            $table->dropColumn('import_hash');
        });
    }
};
