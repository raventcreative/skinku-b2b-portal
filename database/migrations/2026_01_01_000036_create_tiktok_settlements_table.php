<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('tiktok_statement_id')->unique();
            $table->string('payment_status')->nullable()->index();  // PAID | PROCESSING | ...
            $table->string('currency', 8)->nullable();
            // Nilai agregat pencairan (dari Finance API).
            $table->decimal('revenue_amount', 16, 2)->default(0);    // omzet bruto
            $table->decimal('fee_amount', 16, 2)->default(0);        // total fee (disimpan positif)
            $table->decimal('adjustment_amount', 16, 2)->default(0); // penyesuaian lain
            $table->decimal('settlement_amount', 16, 2)->default(0); // net yang cair ke bank
            $table->json('order_ids')->nullable();                   // daftar order dalam pencairan (kalau ada)
            $table->json('raw')->nullable();                         // simpan respons mentah utk M3b
            $table->dateTime('statement_time')->nullable();          // dateTime, hindari overflow 2038
            $table->dateTime('paid_time')->nullable();
            // Posting ke jurnal akuntansi: pending | posted
            $table->string('posting_status', 20)->default('pending')->index();
            $table->unsignedBigInteger('journal_id')->nullable();    // acc_journals.id
            $table->dateTime('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_settlements');
    }
};
