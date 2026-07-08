<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single raw-material stock-in (purchase). Adds to materials.stock and
 * recomputes the material's moving-average cost. cost_before/after snapshot the
 * average around this purchase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained('materials')->cascadeOnDelete();
            $table->string('material_name');
            $table->decimal('quantity', 16, 3);
            $table->decimal('unit_cost', 16, 2);
            $table->decimal('subtotal', 16, 2);
            $table->decimal('cost_before', 16, 2)->nullable();
            $table->decimal('cost_after', 16, 2)->nullable();
            $table->string('supplier_name')->nullable();
            $table->date('purchased_at');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('purchased_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_purchases');
    }
};
