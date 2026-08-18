<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('join_package_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('join_package_id')->constrained('join_packages')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('qty');
            $table->timestamps();
            $table->index('join_package_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('join_package_items');
    }
};
