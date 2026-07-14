<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_returns', function (Blueprint $table) {
            $table->id();
            $table->string('tiktok_return_id')->unique();
            $table->string('tiktok_order_id')->nullable()->index();
            $table->string('status')->nullable();          // status retur dari TikTok
            $table->string('return_type')->nullable();     // REFUND | RETURN_AND_REFUND
            $table->json('line_items')->nullable();        // [{sku, qty}] barang yg diretur
            // hasil review internal: pending | restocked (layak jual, stok +) | rejected (cacat)
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
        Schema::dropIfExists('tiktok_returns');
    }
};
