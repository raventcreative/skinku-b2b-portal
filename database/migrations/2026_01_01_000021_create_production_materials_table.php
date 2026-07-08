<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Materials consumed by a production batch. unit_cost snapshots the material's
 * average cost at production time; subtotal = quantity * unit_cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_id')->constrained('productions')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('materials')->cascadeOnDelete();
            $table->string('material_name');
            $table->string('unit')->nullable();
            $table->decimal('quantity', 16, 3);
            $table->decimal('unit_cost', 16, 2);
            $table->decimal('subtotal', 16, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_materials');
    }
};
