<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deal/kerjasama KOL. Kode dokumen pola SKN-KOL-YYYYMMDD-XXXX (mirip nomor PO).
 *
 * Kolom finansial (total_biaya, status_bayar, rekening) ikut di tabel ini tapi
 * AKSESNYA dibatasi permission kol.deal.finance di lapisan controller & view —
 * tanpa permission itu field-nya disembunyikan dan input-nya dibuang, bukan
 * sekadar disabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_deals', function (Blueprint $table) {
            $table->id();
            $table->string('kode')->unique();
            $table->foreignId('kol_id')->constrained('kols')->cascadeOnDelete();
            $table->enum('jenis', ['vt', 'live']);
            $table->unsignedBigInteger('ratecard_deal')->default(0);
            $table->unsignedInteger('jumlah_slot')->default(1);   // relevan untuk vt
            $table->date('periode_mulai')->nullable();
            $table->date('periode_selesai')->nullable();
            $table->foreignId('pic_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('link_mou')->nullable();
            $table->enum('status', ['draft', 'berjalan', 'selesai', 'batal'])->default('draft');

            // Finansial — dibatasi kol.deal.finance (lihat KolDealController).
            $table->unsignedBigInteger('total_biaya')->default(0);
            $table->enum('status_bayar', ['belum', 'dp', 'lunas'])->default('belum');
            $table->string('no_rekening')->nullable();
            $table->string('bank')->nullable();
            $table->string('atas_nama')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['kol_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_deals');
    }
};
