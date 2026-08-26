<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('po_returns', function (Blueprint $table) {
            // Barang yang diretur datang dari retur PELANGGAN — mitra sudah tak
            // memegang barang di stok sistem (sudah kecatat terjual). Kalau true:
            // apply/void TAK menyentuh stok mitra, cuma restock penerima (HQ/GD,
            // bila kondisi normal) + clawback komisi.
            $table->boolean('from_customer')->default(false)->after('kondisi');
        });
    }

    public function down(): void
    {
        Schema::table('po_returns', function (Blueprint $table) {
            $table->dropColumn('from_customer');
        });
    }
};
