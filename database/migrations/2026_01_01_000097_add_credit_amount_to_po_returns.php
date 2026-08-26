<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('po_returns', function (Blueprint $table) {
            // Nilai barang yang diretur (unit_price × qty) — dipakai untuk POTONG
            // SISA TAGIHAN PO. Diisi saat apply(); efektif tak dihitung lagi saat
            // status void (perhitungan hanya jumlahkan retur status 'applied').
            $table->decimal('credit_amount', 18, 2)->default(0)->after('from_customer');
        });
    }

    public function down(): void
    {
        Schema::table('po_returns', function (Blueprint $table) {
            $table->dropColumn('credit_amount');
        });
    }
};
