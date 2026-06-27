<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'image')) {
                $table->dropColumn('image');
            }
            if (Schema::hasColumn('products', 'image_path')) {
                $table->dropColumn('image_path');
            }
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_orders', 'payment_proof_path')) {
                $table->dropColumn('payment_proof_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('image')->nullable();
            $table->string('image_path')->nullable();
        });
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('payment_proof_path')->nullable();
        });
    }
};
