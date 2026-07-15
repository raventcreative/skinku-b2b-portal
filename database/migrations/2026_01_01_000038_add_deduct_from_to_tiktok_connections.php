<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiktok_connections', function (Blueprint $table) {
            // Batas mulai potong stok: order sebelum tanggal ini dianggap sudah
            // tercakup stok opname (barang sudah keluar sebelum dihitung).
            $table->date('deduct_from')->nullable()->after('auto_deduct');
        });
    }

    public function down(): void
    {
        Schema::table('tiktok_connections', function (Blueprint $table) {
            $table->dropColumn('deduct_from');
        });
    }
};
