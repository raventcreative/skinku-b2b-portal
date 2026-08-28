<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alias username affiliate → KOL. Saat admin menautkan username asing ke KOL
 * (layar "Belum Cocok"), aliasnya disimpan agar import berikutnya untuk
 * username yang sama otomatis cocok (tanpa tautan manual berulang).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_username_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('username', 150)->unique();     // lowercase, tanpa '@'
            $table->foreignId('kol_id')->constrained('kols')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_username_aliases');
    }
};
