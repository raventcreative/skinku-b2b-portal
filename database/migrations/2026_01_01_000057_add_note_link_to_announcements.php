<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tombol link opsional untuk box catatan: link + label ("Klik di sini" / bebas).
 * Terpisah dari auto-hyperlink URL di dalam isi catatan (itu murni tampilan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->string('note_link')->nullable()->after('note_body');
            $table->string('note_link_label', 60)->nullable()->after('note_link');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn(['note_link', 'note_link_label']);
        });
    }
};
