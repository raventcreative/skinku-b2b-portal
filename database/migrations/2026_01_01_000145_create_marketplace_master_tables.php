<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Produk Master e-commerce: tiap unit jualan (satuan/varian/bundle) = 1 baris.
        Schema::create('marketplace_masters', function (Blueprint $t) {
            $t->id();
            $t->string('master_sku')->unique();
            $t->string('name');
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete(); // referensi opsional, BUKAN sumber stok
            $t->integer('base_stock')->nullable();       // null = belum di-set → tak di-push
            $t->decimal('base_price', 12, 2)->nullable();
            $t->timestamp('seeded_at')->nullable();      // patokan reconcile order-mirror (stok)
            $t->timestamps();
        });

        // Override per channel di atas Master.
        Schema::create('marketplace_master_channels', function (Blueprint $t) {
            $t->id();
            $t->foreignId('master_id')->constrained('marketplace_masters')->cascadeOnDelete();
            $t->string('channel', 16); // tiktok | shopee
            $t->integer('stock')->nullable();            // null = ikut Master
            $t->decimal('price', 12, 2)->nullable();     // null = ikut Master
            $t->timestamp('seeded_at')->nullable();
            $t->timestamps();
            $t->unique(['master_id', 'channel']);
        });

        // Listing tertaut ke master + jejak push harga (jejak push stok sudah ada dari Fase 1).
        Schema::table('marketplace_listings', function (Blueprint $t) {
            $t->foreignId('master_id')->nullable()->after('id')->constrained('marketplace_masters')->nullOnDelete();
            $t->decimal('last_pushed_price', 12, 2)->nullable()->after('last_pushed_qty');
            $t->string('last_price_status', 16)->nullable()->after('last_pushed_price'); // ok | failed
            $t->text('last_price_error')->nullable()->after('last_price_status');
            $t->timestamp('last_price_pushed_at')->nullable()->after('last_price_error');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_listings', function (Blueprint $t) {
            $t->dropConstrainedForeignId('master_id');
            $t->dropColumn(['last_pushed_price', 'last_price_status', 'last_price_error', 'last_price_pushed_at']);
        });
        Schema::dropIfExists('marketplace_master_channels');
        Schema::dropIfExists('marketplace_masters');
    }
};
