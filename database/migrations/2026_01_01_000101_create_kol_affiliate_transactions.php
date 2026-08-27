<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3a: transaksi affiliate (order kreator TikTok/Shopee yang jualin produk
 * SKINKU). Sumber import file / agen scraper. Dedup by (platform, order_id).
 * kol_id null = username belum cocok ke KOL ("Belum Cocok").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_affiliate_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 20);
            $table->string('order_id');
            $table->foreignId('kol_id')->nullable()->constrained('kols')->nullOnDelete();
            $table->string('raw_username')->nullable();
            $table->unsignedBigInteger('gmv')->default(0);
            $table->unsignedBigInteger('commission')->nullable();
            $table->unsignedInteger('qty')->nullable();
            $table->string('product')->nullable();
            $table->string('status')->nullable();
            $table->string('content_type', 30)->nullable();
            $table->date('order_date');
            $table->string('source', 10)->default('import'); // import | agent | manual
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['platform', 'order_id']);
            $table->index('kol_id');
            $table->index('order_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_affiliate_transactions');
    }
};
