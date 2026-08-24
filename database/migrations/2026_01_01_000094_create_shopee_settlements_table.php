<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('order_sn')->unique();
            $table->string('currency', 8)->nullable();
            $table->decimal('escrow_amount', 16, 2)->default(0);
            $table->decimal('buyer_total_amount', 16, 2)->default(0);
            $table->decimal('commission_fee', 16, 2)->default(0);
            $table->decimal('service_fee', 16, 2)->default(0);
            $table->decimal('campaign_fee', 16, 2)->default(0);
            $table->decimal('seller_transaction_fee', 16, 2)->default(0);
            $table->decimal('actual_shipping_fee', 16, 2)->default(0);
            $table->decimal('buyer_paid_shipping_fee', 16, 2)->default(0);
            $table->decimal('shopee_shipping_rebate', 16, 2)->default(0);
            $table->decimal('escrow_tax', 16, 2)->default(0);
            $table->decimal('withholding_tax', 16, 2)->default(0);
            $table->decimal('total_adjustment_amount', 16, 2)->default(0);
            $table->dateTime('escrow_release_time')->nullable();
            $table->json('raw')->nullable();
            $table->string('posting_status', 20)->default('pending')->index();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_settlements');
    }
};
