<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penjualan mitra ke customer akhir (barang keluar berbentuk "nota jual").
 *
 * Ini BUKAN purchase_orders: PO itu HQ→mitra, ini mitra→customer akhir. Sengaja
 * tabel sendiri supaya siklus & kepemilikannya tak tercampur. Satu penjualan =
 * satu customer, banyak baris produk (mirip Buat PO), dan menurunkan stok mitra
 * pemiliknya lewat gerakan OUT yang mereferensi baris ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_sales', function (Blueprint $table) {
            $table->id();
            $table->string('sale_number')->unique();
            // Pemilik stok yang berkurang — distributor/reseller yang menjual.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('customer_name')->nullable();   // customer akhir, nama bebas
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('notes', 1000)->nullable();
            $table->date('sold_at');                       // tanggal jual (wall-clock)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'sold_at']);
        });

        Schema::create('partner_sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_sale_id')->constrained('partner_sales')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            // Nama disimpan (bukan cuma relasi): kalau produk berubah nama/dihapus,
            // nota lama tetap terbaca apa adanya.
            $table->string('product_name');
            $table->integer('qty');
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('total_price', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_sale_items');
        Schema::dropIfExists('partner_sales');
    }
};
