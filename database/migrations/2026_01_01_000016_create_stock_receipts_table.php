<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Goods-receipt (incoming stock) header. Each receipt records one delivery of
 * products into HQ stock. Posting a receipt raises products.hq_stock and
 * recomputes each product's moving-average HPP (products.cogs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number')->unique();
            $table->string('supplier_name')->nullable();
            $table->string('reference_no')->nullable(); // supplier invoice / DO number
            $table->date('received_at');
            $table->decimal('total_cost', 16, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_receipts');
    }
};
