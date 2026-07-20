<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom X sheet "Listing KOL": AGENCY/NON AGENCY. Teks bebas (CMEDIA, OUR GOOD
 * MEDIA, dst); kosong = non-agency. Di kols, bukan per-screening — keagenan
 * melekat ke orangnya, bukan ke satu kali kurasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kols', function (Blueprint $table) {
            $table->string('agency')->nullable()->after('provinsi');
        });
    }

    public function down(): void
    {
        Schema::table('kols', function (Blueprint $table) {
            $table->dropColumn('agency');
        });
    }
};
