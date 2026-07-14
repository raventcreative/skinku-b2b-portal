<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1 SKU TikTok bisa = beberapa produk SKINKU × qty (mis. "Soap-3" = Body Soap ×3,
 * atau bundle = sabun ×1 + lotion ×1 + scrub ×1). Jadi peta bukan lagi 1:1:
 * tambah kolom qty (jumlah per unit SKU) & izinkan banyak baris per SKU.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiktok_sku_maps', function (Blueprint $table) {
            $table->unsignedInteger('qty')->default(1)->after('product_id');
            $table->dropUnique(['tiktok_sku']);              // boleh banyak komponen per SKU
            $table->unique(['tiktok_sku', 'product_id']);    // tapi tak boleh produk kembar di 1 SKU
        });
    }

    public function down(): void
    {
        Schema::table('tiktok_sku_maps', function (Blueprint $table) {
            $table->dropUnique(['tiktok_sku', 'product_id']);
            $table->dropColumn('qty');
            $table->unique('tiktok_sku');
        });
    }
};
