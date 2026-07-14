<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_orders', function (Blueprint $table) {
            $table->id();
            $table->string('tiktok_order_id')->unique();
            $table->string('status')->nullable()->index();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->string('currency', 8)->nullable();
            $table->json('line_items')->nullable();        // [{seller_sku, product_name, quantity, ...}]
            // status potong stok internal: pending | deducted | skipped
            $table->string('stock_status', 20)->default('pending')->index();
            $table->timestamp('order_created_at')->nullable();
            $table->timestamp('deducted_at')->nullable();
            $table->foreignId('deducted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Peta SKU TikTok → produk SKINKU (buat SKU yang tidak otomatis cocok).
        Schema::create('tiktok_sku_maps', function (Blueprint $table) {
            $table->id();
            $table->string('tiktok_sku')->unique();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_sku_maps');
        Schema::dropIfExists('tiktok_orders');
    }
};
