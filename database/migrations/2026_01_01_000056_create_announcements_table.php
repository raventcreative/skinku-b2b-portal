<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengumuman dashboard PER ROLE: satu baris per role. Dua bagian —
 *  - Box catatan (teks) yang nempel di dashboard,
 *  - Popup banner (gambar via ImageService + link opsional) yang muncul tiap
 *    login (sekali per sesi).
 * Gambar banner disimpan polimorfik di tabel files (fileable = announcement),
 * makanya id integer, bukan role sebagai PK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('role')->unique();
            $table->boolean('note_enabled')->default(false);
            $table->string('note_title')->nullable();
            $table->text('note_body')->nullable();
            $table->boolean('banner_enabled')->default(false);
            $table->string('banner_link')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
