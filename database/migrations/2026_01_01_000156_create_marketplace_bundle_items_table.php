<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Resep bundling: master bundle = qty × master komponen. Stok bundle dihitung dari komponen (min floor(stok/qty))
        // dan order bundle memotong stok komponen — lihat MarketplaceMasterService::effectiveStock/applyOrderDelta.
        Schema::create('marketplace_bundle_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bundle_id')->constrained('marketplace_masters')->cascadeOnDelete();
            $t->foreignId('component_id')->constrained('marketplace_masters')->cascadeOnDelete();
            $t->unsignedSmallInteger('qty')->default(1);
            $t->timestamps();
            $t->unique(['bundle_id', 'component_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_bundle_items');
    }
};
