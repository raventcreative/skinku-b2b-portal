<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Line items of a goods receipt. `unit_cost` is the purchase price (HPP) per
 * unit for this delivery. cogs_before/after snapshot the product's average HPP
 * around this receipt for traceability.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_receipt_id')->constrained('stock_receipts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('product_name'); // snapshot at receipt time
            $table->integer('quantity');
            $table->decimal('unit_cost', 16, 2);       // HPP beli per unit
            $table->decimal('subtotal', 16, 2);        // quantity * unit_cost
            $table->decimal('cogs_before', 16, 2)->nullable();
            $table->decimal('cogs_after', 16, 2)->nullable();
            $table->timestamps();

            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_receipt_items');
    }
};
