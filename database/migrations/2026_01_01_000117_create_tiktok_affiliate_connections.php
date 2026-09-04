<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token OAuth app "Seller Analitik" (Affiliate Seller API) — TERPISAH dari
 * tiktok_connections (app Shop). Toko yang sama diotorisasi dua app → dua
 * shop_cipher berbeda, jadi tokennya disimpan sendiri di sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_affiliate_connections', function (Blueprint $table) {
            $table->id();
            $table->string('shop_id')->nullable();
            $table->text('shop_cipher')->nullable();
            $table->string('shop_name')->nullable();
            $table->string('region', 16)->nullable();
            $table->string('seller_name')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('access_expires_at')->nullable();
            $table->timestamp('refresh_expires_at')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_affiliate_connections');
    }
};
