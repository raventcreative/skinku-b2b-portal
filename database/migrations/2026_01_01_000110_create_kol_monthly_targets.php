<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Override target per-bulan (budget/views/gmv/margin). Bila ada untuk suatu
 * bulan, dipakai menggantikan setelan global (AppSetting) untuk bulan itu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_monthly_targets', function (Blueprint $table) {
            $table->id();
            $table->char('month', 7)->unique();           // YYYY-MM
            $table->unsignedBigInteger('budget')->nullable();
            $table->unsignedBigInteger('views_target')->nullable();
            $table->unsignedBigInteger('gmv_target')->nullable();
            $table->decimal('margin', 4, 2)->nullable();   // 0..1
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_monthly_targets');
    }
};
