<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiktok_orders', function (Blueprint $table) {
            // HPP dikunci saat barang keluar, dipakai lagi saat order sampai supaya
            // "Persediaan Dalam Perjalanan" bersih (masuk & keluar nilainya sama).
            $table->decimal('hpp_amount', 16, 2)->default(0)->after('total_amount');
            // Jejak jurnal (idempoten): barang keluar → transit, order sampai → penjualan.
            $table->unsignedBigInteger('transit_journal_id')->nullable()->after('deducted_by');
            $table->unsignedBigInteger('sale_journal_id')->nullable()->after('transit_journal_id');
        });
    }

    public function down(): void
    {
        Schema::table('tiktok_orders', function (Blueprint $table) {
            $table->dropColumn(['hpp_amount', 'transit_journal_id', 'sale_journal_id']);
        });
    }
};
