<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link Komunitas WA PER ROLE: satu baris per role. Muncul jadi tombol
 * "Gabung Komunitas WA" di sidebar untuk role yang bersangkutan. Gambar QR
 * opsional disimpan polimorfik di tabel files (fileable = community_link),
 * makanya id integer + role unique, bukan role sebagai PK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_links', function (Blueprint $table) {
            $table->id();
            $table->string('role')->unique();
            $table->boolean('enabled')->default(false);
            $table->string('label')->nullable();   // teks tombol; default "Gabung Komunitas WA"
            $table->string('link')->nullable();     // URL grup WA
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_links');
    }
};
