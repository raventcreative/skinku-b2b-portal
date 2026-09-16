<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cache detail produk TikTok (judul/foto/harga) per product_id, diambil dari
 * Products API saat kartu produk dibuka di Chat E-commerce. Chat cuma dikirim
 * product_id oleh TikTok; ini yang membuatnya bisa tampil nama+foto+harga.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_products', function (Blueprint $table) {
            $table->id();
            $table->string('product_id')->unique();
            $table->string('title')->nullable();
            $table->string('image_url', 1024)->nullable();
            $table->unsignedBigInteger('price')->nullable();
            $table->string('currency', 8)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_products');
    }
};
