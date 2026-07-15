<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiktok_connections', function (Blueprint $table) {
            // Saklar pembukuan TikTok. DEFAULT MATI — kode boleh ter-deploy tanpa
            // menyentuh buku produksi sampai user menyalakannya sendiri.
            $table->boolean('journal_enabled')->default(false)->after('deduct_from');
        });
    }

    public function down(): void
    {
        Schema::table('tiktok_connections', function (Blueprint $table) {
            $table->dropColumn('journal_enabled');
        });
    }
};
