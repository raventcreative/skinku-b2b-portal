<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Berat satuan produk dalam GRAM. Dipakai untuk booking kurir (Tahap 2):
            // total berat PO = Σ(qty × weight_grams). Default 0, diisi bertahap.
            $table->unsignedInteger('weight_grams')->default(0)->after('cogs');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('weight_grams');
        });
    }
};
