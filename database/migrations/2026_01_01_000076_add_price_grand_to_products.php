<?php

use App\Support\GrandPriceList;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('price_grand', 15, 2)->nullable()->after('price_distributor');
        });

        // Seed harga Grand dari pricelist resmi (cocokkan nama produk).
        GrandPriceList::apply();
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('price_grand');
        });
    }
};
