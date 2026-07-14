<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiktok_connections', function (Blueprint $table) {
            // kalau true: setiap tarik order, order yg dikirim+cocok langsung dipotong stok
            $table->boolean('auto_deduct')->default(false)->after('last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('tiktok_connections', function (Blueprint $table) {
            $table->dropColumn('auto_deduct');
        });
    }
};
