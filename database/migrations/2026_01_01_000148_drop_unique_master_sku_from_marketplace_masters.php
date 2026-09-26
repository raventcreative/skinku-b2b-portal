<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_masters', function (Blueprint $t) {
            $t->dropUnique(['master_sku']); // name_key kini kunci dedup; master_sku cuma perwakilan (boleh sama antar master, mis. SKU sama beda judul antar channel)
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_masters', function (Blueprint $t) {
            $t->unique('master_sku');
        });
    }
};
