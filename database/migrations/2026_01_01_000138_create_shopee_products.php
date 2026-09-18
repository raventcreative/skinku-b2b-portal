<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cache detail item Shopee untuk kartu produk chat (di-isi via get_item_base_info). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_products', function (Blueprint $table) {
            $table->id();
            $table->string('item_id')->unique();
            $table->string('title')->nullable();
            $table->string('image_url', 1024)->nullable();
            $table->decimal('price', 15, 2)->nullable();
            $table->string('currency', 8)->default('IDR');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_products');
    }
};
