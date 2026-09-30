<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Jejak push FOTO produk per listing — paralel dgn jejak push stok (last_status/…),
        // harga (last_price_*) dan konten (last_content_*).
        Schema::table('marketplace_listings', function (Blueprint $t) {
            $t->string('last_photo_status', 16)->nullable()->after('content_hash'); // ok | failed
            $t->text('last_photo_error')->nullable()->after('last_photo_status');
            $t->timestamp('last_photo_pushed_at')->nullable()->after('last_photo_error');
            $t->string('photo_hash')->nullable()->after('last_photo_pushed_at');    // hash daftar foto terkirim (diff-guard)
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_listings', function (Blueprint $t) {
            $t->dropColumn(['last_photo_status', 'last_photo_error', 'last_photo_pushed_at', 'photo_hash']);
        });
    }
};
