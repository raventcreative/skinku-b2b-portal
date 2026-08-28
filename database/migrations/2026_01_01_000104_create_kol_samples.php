<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sampel produk yang dikirim ke KOL untuk sebuah deal (pending → shipped →
 * received). HPP-nya (units × unit_cost) bisa ditambahkan ke biaya deal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kol_deal_id')->nullable()->constrained('kol_deals')->cascadeOnDelete();
            $table->foreignId('kol_id')->constrained('kols')->cascadeOnDelete();
            $table->string('product');
            $table->unsignedInteger('units')->default(1);
            $table->unsignedBigInteger('unit_cost')->default(0); // HPP per unit
            $table->string('courier')->nullable();
            $table->string('tracking_no')->nullable();
            $table->string('status', 12)->default('pending'); // pending | shipped | received
            $table->date('shipped_at')->nullable();
            $table->date('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('kol_deal_id');
            $table->index('kol_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_samples');
    }
};
