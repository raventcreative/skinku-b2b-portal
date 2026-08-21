<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Retur PO (partial/full): balikin stok + clawback komisi + refund manual.
        Schema::create('po_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->string('status')->default('pending');   // pending / applied / rejected / void
            $table->string('kondisi')->default('normal');   // normal (masuk stok lagi) / rusak (write-off)
            $table->text('reason')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->index(['purchase_order_id', 'status']);
        });

        Schema::create('po_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('po_return_id')->constrained('po_returns')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained('purchase_order_items')->cascadeOnDelete();
            $table->unsignedInteger('qty');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('po_return_items');
        Schema::dropIfExists('po_returns');
    }
};
