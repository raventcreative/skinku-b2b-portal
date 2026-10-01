<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Varian ala Desty: master INDUK (nama/foto/deskripsi/kategori) + master ANAK per opsi varian (SKU/harga/stok/
        // barcode + listing sendiri). Anak tetap baris marketplace_masters biasa → mesin stok/harga/order tak berubah.
        Schema::table('marketplace_masters', function (Blueprint $t) {
            $t->foreignId('parent_id')->nullable()->after('id')->constrained('marketplace_masters')->cascadeOnDelete();
            $t->string('variant_type', 50)->nullable()->after('parent_id');  // di induk, mis. "Qty" / "Ukuran"
            $t->string('variant_name', 100)->nullable()->after('variant_type'); // di anak, mis. "Scrub 3 Pcs"
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_masters', function (Blueprint $t) {
            $t->dropConstrainedForeignId('parent_id');
            $t->dropColumn(['variant_type', 'variant_name']);
        });
    }
};
