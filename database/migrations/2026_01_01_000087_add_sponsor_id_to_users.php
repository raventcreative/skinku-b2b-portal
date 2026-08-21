<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Jalur REKRUTMEN (siapa merekrut member ini) — TERPISAH dari upline_id
            // (jalur pasok). Nullable: member tanpa perekrut / daftar mandiri.
            $table->foreignId('sponsor_id')->nullable()->after('upline_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sponsor_id');
        });
    }
};
