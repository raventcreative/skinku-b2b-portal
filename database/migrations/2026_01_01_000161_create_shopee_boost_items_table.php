<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Naikkan Produk" Shopee otomatis: produk (item Shopee — naik per produk, bukan per varian) yang dijaga tetap naik.
 * Maks 5 (batas Shopee). Status putaran terakhir dicatat per produk. Additive, tak menyentuh data lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_boost_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('item_id')->unique();
            $table->string('title')->nullable();
            $table->timestamp('last_boosted_at')->nullable();
            $table->timestamp('boosted_until')->nullable();
            $table->string('last_status')->nullable(); // ok | failed
            $table->text('last_error')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_boost_items');
    }
};
