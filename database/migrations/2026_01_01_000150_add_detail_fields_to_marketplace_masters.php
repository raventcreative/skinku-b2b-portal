<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_masters', function (Blueprint $t) {
            $t->string('category')->nullable()->after('name');
            $t->text('description')->nullable()->after('category');
            $t->unsignedInteger('weight_g')->nullable()->after('description');
            $t->unsignedInteger('length_cm')->nullable()->after('weight_g');
            $t->unsignedInteger('width_cm')->nullable()->after('length_cm');
            $t->unsignedInteger('height_cm')->nullable()->after('width_cm');
            $t->string('barcode')->nullable()->after('height_cm');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_masters', function (Blueprint $t) {
            $t->dropColumn(['category', 'description', 'weight_g', 'length_cm', 'width_cm', 'height_cm', 'barcode']);
        });
    }
};
