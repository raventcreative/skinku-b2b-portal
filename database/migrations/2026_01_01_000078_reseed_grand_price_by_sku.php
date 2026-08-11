<?php

use App\Support\GrandPriceList;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Seed by-name (000076) kena 0 match di prod (nama brand). Seed ulang by SKU.
        GrandPriceList::applyBySku();
    }

    public function down(): void
    {
        // Tak membalik harga (data koreksi).
    }
};
