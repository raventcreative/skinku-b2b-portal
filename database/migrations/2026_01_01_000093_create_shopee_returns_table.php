<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_returns', function (Blueprint $table) {
            $table->id();
            $table->string('shopee_return_sn')->unique();
            $table->string('shopee_order_sn')->nullable()->index();
            $table->string('status')->nullable();
            $table->string('return_reason')->nullable();
            $table->json('line_items')->nullable();
            $table->string('review_status', 20)->default('pending')->index();
            $table->text('review_note')->nullable();
            $table->timestamp('return_created_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_returns');
    }
};
