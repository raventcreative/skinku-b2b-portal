<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->decimal('shipping_cost', 15, 2)->default(0)->after('discount');
            // unpaid | awaiting_verification | paid | rejected
            $table->string('payment_status')->default('unpaid')->after('total_amount')->index();
            $table->string('payment_proof_path')->nullable()->after('payment_status');
            $table->text('payment_note')->nullable()->after('payment_proof_path');
            $table->timestamp('paid_at')->nullable()->after('payment_note');
            $table->unsignedBigInteger('payment_verified_by')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn([
                'shipping_cost', 'payment_status', 'payment_proof_path',
                'payment_note', 'paid_at', 'payment_verified_by',
            ]);
        });
    }
};
