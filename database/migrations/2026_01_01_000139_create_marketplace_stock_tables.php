<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_stocks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $t->integer('quantity')->default(0);
            $t->timestamp('seeded_at')->nullable();
            $t->timestamps();
        });

        Schema::create('marketplace_listings', function (Blueprint $t) {
            $t->id();
            $t->string('channel', 16);            // tiktok | shopee
            $t->string('seller_sku');
            $t->string('item_id')->nullable();    // shopee item_id / tiktok product_id
            $t->string('variation_id')->nullable(); // shopee model_id / tiktok sku_id
            $t->string('warehouse_id')->nullable();
            $t->string('title')->nullable();
            $t->integer('last_pushed_qty')->nullable();
            $t->string('last_status', 16)->nullable(); // ok | failed | unmapped
            $t->text('last_error')->nullable();
            $t->timestamp('last_pushed_at')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->unique(['channel', 'seller_sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_listings');
        Schema::dropIfExists('marketplace_stocks');
    }
};
