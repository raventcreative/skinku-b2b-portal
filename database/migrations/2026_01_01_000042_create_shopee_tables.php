<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integrasi Shopee — struktur mengikuti pola TikTok yang sudah terbukti live.
 * Sengaja tabel terpisah (bukan digabung dengan TikTok): tiap marketplace punya
 * bentuk data & siklus status sendiri, dan menyatukannya sekarang berarti
 * membongkar integrasi TikTok yang sedang jalan di produksi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_connections', function (Blueprint $table) {
            $table->id();
            $table->string('shop_id')->unique();
            $table->string('shop_name')->nullable();
            $table->string('region', 8)->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            // DATETIME, bukan TIMESTAMP — pelajaran dari TikTok (expiry jauh ke
            // depan bisa melewati batas 2038 milik TIMESTAMP).
            $table->dateTime('access_expires_at')->nullable();   // ±4 jam saja!
            $table->dateTime('refresh_expires_at')->nullable();  // ±30 hari
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('last_synced_at')->nullable();
            $table->boolean('auto_deduct')->default(false);
            // Batas mulai potong stok — order sebelum ini dianggap sudah tercakup
            // stok opname (pelajaran mahal dari TikTok).
            $table->date('deduct_from')->nullable();
            $table->timestamps();
        });

        Schema::create('shopee_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_sn')->unique();          // nomor order Shopee
            $table->string('status')->nullable()->index(); // READY_TO_SHIP, SHIPPED, COMPLETED, CANCELLED...
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('hpp_amount', 16, 2)->default(0);
            $table->string('currency', 8)->nullable();
            $table->json('line_items')->nullable();        // [{sku, name, qty}] ternormalisasi
            $table->string('stock_status', 20)->default('pending')->index(); // pending|deducted
            $table->dateTime('order_created_at')->nullable();
            $table->dateTime('deducted_at')->nullable();
            $table->foreignId('deducted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Resep SKU: 1 SKU Shopee → banyak produk × qty (bundling), sama seperti TikTok.
        Schema::create('shopee_sku_maps', function (Blueprint $table) {
            $table->id();
            $table->string('shopee_sku');
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('qty')->default(1);
            $table->timestamps();
            $table->unique(['shopee_sku', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_sku_maps');
        Schema::dropIfExists('shopee_orders');
        Schema::dropIfExists('shopee_connections');
    }
};
