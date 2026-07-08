<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw materials (bahan baku) master. `stock` is the on-hand quantity in the
 * material's own unit (kg/pcs/botol/ml); `avg_cost` is the moving-average cost
 * per unit, updated on each purchase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('unit')->default('pcs'); // kg / pcs / botol / ml / gram
            $table->decimal('stock', 16, 3)->default(0);
            $table->decimal('avg_cost', 16, 2)->default(0);
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materials');
    }
};
