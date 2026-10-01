<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kategori marketplace per channel (pohon & ID TikTok ≠ Shopee) + atribut wajib kategori itu, dipilih dari API.
        // Atribut disimpan ternormalisasi: [{id, values:[{id, name, unit?}]}] (id nilai '' = isian bebas).
        Schema::table('marketplace_masters', function (Blueprint $t) {
            $t->string('tiktok_category_id', 32)->nullable()->after('barcode');
            $t->string('tiktok_category_name', 500)->nullable()->after('tiktok_category_id'); // jalur "A > B > C"
            $t->json('tiktok_attributes')->nullable()->after('tiktok_category_name');
            $t->string('shopee_category_id', 32)->nullable()->after('tiktok_attributes');
            $t->string('shopee_category_name', 500)->nullable()->after('shopee_category_id');
            $t->json('shopee_attributes')->nullable()->after('shopee_category_name');
            $t->json('shopee_brand')->nullable()->after('shopee_attributes'); // {brand_id, original_brand_name}
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_masters', function (Blueprint $t) {
            $t->dropColumn(['tiktok_category_id', 'tiktok_category_name', 'tiktok_attributes', 'shopee_category_id', 'shopee_category_name', 'shopee_attributes', 'shopee_brand']);
        });
    }
};
