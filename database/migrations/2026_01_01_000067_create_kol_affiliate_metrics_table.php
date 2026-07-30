<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_affiliate_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kol_id')->constrained('kols')->cascadeOnDelete();
            $table->date('period_month');
            $table->string('stage', 30)->default('registered');
            $table->unsignedInteger('content_count')->default(0);
            $table->unsignedInteger('live_count')->default(0);
            $table->unsignedInteger('order_count')->default(0);
            $table->decimal('gmv', 16, 2)->default(0);
            $table->decimal('conversion_rate', 8, 4)->nullable();
            $table->decimal('retention_rate', 8, 4)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['kol_id', 'period_month']);
            $table->index(['period_month', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_affiliate_metrics');
    }
};
