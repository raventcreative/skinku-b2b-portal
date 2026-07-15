<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiktok_settlements', function (Blueprint $table) {
            $table->string('kind', 80)->nullable()->index();  // keterangan terjemahan (Penjualan / Iklan / dll)
            $table->string('kind_raw', 190)->nullable();       // jenis asli dari TikTok
        });
    }

    public function down(): void
    {
        Schema::table('tiktok_settlements', function (Blueprint $table) {
            $table->dropColumn(['kind', 'kind_raw']);
        });
    }
};
