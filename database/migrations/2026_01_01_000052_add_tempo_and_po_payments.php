<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tempo/cicilan PO (case nyata: customer minta bayar bertahap tapi barang tetap
 * diproses).
 *
 * is_tempo = pintu TERKONTROL melewati gerbang pembayaran: hanya PO yang
 * ditandai tempo oleh admin yang boleh diproses sebelum lunas — gerbang untuk
 * PO biasa tetap terkunci. payment_status TIDAK diubah artinya: tetap "unpaid"
 * sampai benar-benar lunas, jadi "siapa yang belum lunas" tetap satu sumber
 * kebenaran.
 *
 * po_payments = cicilan yang dicatat admin saat uang masuk. Lunas otomatis
 * ketika total cicilan mencapai total tagihan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->boolean('is_tempo')->default(false)->after('payment_status');
            $table->date('tempo_due_date')->nullable()->after('is_tempo');
            $table->string('tempo_notes', 500)->nullable()->after('tempo_due_date');
        });

        Schema::create('po_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('paid_at');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['purchase_order_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('po_payments');
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['is_tempo', 'tempo_due_date', 'tempo_notes']);
        });
    }
};
