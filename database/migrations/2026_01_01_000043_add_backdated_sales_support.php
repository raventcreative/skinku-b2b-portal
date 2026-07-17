<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dukungan pencatatan penjualan distributor yang BACK-DATE.
 *
 * Masalahnya: barang untuk PO 8–14 Juli sudah keluar gudang SEBELUM stok opname
 * 14 Juli, jadi hitungan opname sudah memperhitungkannya. Kalau PO-nya diinput
 * sekarang dan memotong stok lagi → stok hilang dua kali.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Setelan aplikasi sederhana (key/value) — sebelumnya tak ada tempat
        // menyimpan setelan global seperti batas tanggal potong stok.
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            // Tanggal transaksi sebenarnya. created_at = kapan BARIS dibuat, yang
            // untuk entri back-date bukan tanggal ordernya. Null = pakai created_at.
            $table->date('order_date')->nullable()->after('user_role')->index();
            // Ditandai saat PO diselesaikan tanpa memotong stok (pra-opname).
            $table->boolean('stock_skipped')->default(false)->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['order_date', 'stock_skipped']);
        });
        Schema::dropIfExists('app_settings');
    }
};
