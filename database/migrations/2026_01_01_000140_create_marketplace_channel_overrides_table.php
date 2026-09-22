<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_channel_overrides', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->string('channel', 16); // tiktok | shopee
            $t->integer('quantity')->default(0);
            $t->timestamp('seeded_at')->nullable();
            $t->timestamps();
            $t->unique(['product_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_channel_overrides');
    }
};
