<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('join_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('target_role', 50); // reseller_bronze | reseller_gold
            $table->decimal('price', 14, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('join_packages');
    }
};
