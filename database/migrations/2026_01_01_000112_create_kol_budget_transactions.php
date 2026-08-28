<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengeluaran budget endorse tambahan di luar deal (boost iklan, hadiah,
 * ongkir kirim sampel massal, dll). Diagregat ke "spent" bulan yang sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_budget_transactions', function (Blueprint $table) {
            $table->id();
            $table->char('month', 7);                 // YYYY-MM
            $table->string('category', 20);           // fee/sample/gift/boost/other
            $table->unsignedBigInteger('amount')->default(0);
            $table->string('note', 200)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_budget_transactions');
    }
};
