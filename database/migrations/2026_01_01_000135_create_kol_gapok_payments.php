<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pembayaran gaji Tim Gapok — BANYAK baris per (kol, bulan) karena bayar sering
 * dicicil 2-3 kali (tak full di depan). Total dibayar per bulan = SUM(amount);
 * dibandingkan dgn kol_gapok_salaries.monthly_salary untuk tahu sisa/lunas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_gapok_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kol_id')->constrained('kols')->cascadeOnDelete();
            $table->date('period'); // tanggal 1 bulan gaji yang dibayar (YYYY-MM-01)
            $table->unsignedBigInteger('amount');
            $table->date('paid_at'); // tanggal transfer/bayar
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['kol_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_gapok_payments');
    }
};
