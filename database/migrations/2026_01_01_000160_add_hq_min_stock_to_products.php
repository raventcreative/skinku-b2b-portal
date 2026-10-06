<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stok minimum PUSAT (HQ) per produk — pengingat stok HQ menipis (banner Dashboard + tanda di Produk Master,
 * Pemantauan Stok, Laporan Stok HQ). Kosong = tanpa pengingat. Additive, tak menyentuh data lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('hq_min_stock')->nullable()->after('hq_stock');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('hq_min_stock');
        });
    }
};
