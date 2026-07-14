<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_connections', function (Blueprint $table) {
            $table->id();
            $table->string('shop_id')->nullable()->index();   // dari authorization/shops
            $table->string('shop_cipher')->nullable();         // wajib buat panggil API toko
            $table->string('shop_name')->nullable();
            $table->string('region', 10)->nullable();
            $table->string('seller_name')->nullable();
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('access_expires_at')->nullable();
            $table->timestamp('refresh_expires_at')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_connections');
    }
};
