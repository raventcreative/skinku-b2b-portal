<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Memori/Pengetahuan" Asisten AI: konteks bisnis yang diisi admin (per bagian
 * terpandu) lalu disuntikkan ke system-prompt tiap obrolan, biar asisten paham
 * SKINKU dan tak menjawab generik. Satu baris per bagian (section unik), isi
 * `text` biar muat paragraf panjang. Lihat AI_ASSISTANT_SPEC.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_knowledge', function (Blueprint $table) {
            $table->id();
            $table->string('section')->unique();   // kunci bagian (business, team, dst)
            $table->text('content')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_knowledge');
    }
};
