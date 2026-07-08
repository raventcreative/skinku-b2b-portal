<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A production batch (manufacturing order). Consumes raw materials + other
 * costs, outputs `output_qty` finished units, and computes
 * hpp_per_unit = total_cost / output_qty. Posting raises the finished product's
 * hq_stock and updates its moving-average HPP (products.cogs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productions', function (Blueprint $table) {
            $table->id();
            $table->string('production_number')->unique();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('product_name');
            $table->date('produced_at');
            $table->integer('output_qty');
            $table->decimal('material_cost', 16, 2)->default(0);
            $table->decimal('other_cost', 16, 2)->default(0);
            $table->decimal('total_cost', 16, 2)->default(0);
            $table->decimal('hpp_per_unit', 16, 2)->default(0);
            $table->decimal('cogs_before', 16, 2)->nullable();
            $table->decimal('cogs_after', 16, 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('produced_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productions');
    }
};
