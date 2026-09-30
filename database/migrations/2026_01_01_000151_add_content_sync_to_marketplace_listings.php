<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Jejak push KONTEN produk (deskripsi/berat/dimensi) per listing — paralel dgn jejak
        // push stok (last_status/…) dan harga (last_price_*).
        Schema::table('marketplace_listings', function (Blueprint $t) {
            $t->string('last_content_status', 16)->nullable()->after('last_price_pushed_at'); // ok | failed
            $t->text('last_content_error')->nullable()->after('last_content_status');
            $t->timestamp('last_content_pushed_at')->nullable()->after('last_content_error');
            $t->string('content_hash')->nullable()->after('last_content_pushed_at');          // hash payload terkirim (diff-guard)
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_listings', function (Blueprint $t) {
            $t->dropColumn(['last_content_status', 'last_content_error', 'last_content_pushed_at', 'content_hash']);
        });
    }
};
