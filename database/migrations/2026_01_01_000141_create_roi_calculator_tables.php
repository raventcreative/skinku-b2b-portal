<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Setelan biaya global (default %/cap/rupiah) — 1 baris (id=1).
        Schema::create('roi_settings', function (Blueprint $t) {
            $t->id();
            $t->decimal('admin_pct', 6, 3)->default(8);
            $t->decimal('voucher_pct', 6, 3)->default(4.5);
            $t->decimal('komisi_pct', 6, 3)->default(5.5);
            $t->unsignedBigInteger('komisi_cap')->default(650000);
            $t->decimal('mall_pct', 6, 3)->default(1.8);
            $t->decimal('pajak_pct', 6, 3)->default(0.5);
            $t->decimal('operasional_pct', 6, 3)->default(3);
            $t->decimal('affiliate_pct', 6, 3)->default(5);
            $t->unsignedInteger('packing_default')->default(1000);
            $t->unsignedInteger('proses_order_default')->default(1250);
            $t->timestamps();
        });

        // Baris produk tersimpan. Kolom override nullable = "ikut global";
        // modal null = "ikut COGS produk".
        Schema::create('roi_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('selling_price')->default(0);
            $t->unsignedBigInteger('modal')->nullable();
            $t->unsignedInteger('packing')->nullable();
            $t->unsignedInteger('proses_order')->nullable();
            $t->decimal('admin_pct', 6, 3)->nullable();
            $t->decimal('voucher_pct', 6, 3)->nullable();
            $t->decimal('komisi_pct', 6, 3)->nullable();
            $t->unsignedBigInteger('komisi_cap')->nullable();
            $t->decimal('mall_pct', 6, 3)->nullable();
            $t->decimal('pajak_pct', 6, 3)->nullable();
            $t->decimal('operasional_pct', 6, 3)->nullable();
            $t->decimal('affiliate_pct', 6, 3)->nullable();
            $t->timestamps();
            $t->unique('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roi_items');
        Schema::dropIfExists('roi_settings');
    }
};
