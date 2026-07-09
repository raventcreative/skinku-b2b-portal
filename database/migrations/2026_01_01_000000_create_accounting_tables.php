<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Akuntansi SKINKU — Fase 1: struktur data.
 * Double-entry, multi-cabang (cabang = dimensi, bukan akun terpisah).
 * Asumsi: Laravel 10/11 + MySQL, ditambahkan ke app SKINKU existing.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Cabang / lokasi (Surabaya Timur, Jakarta, Surabaya Barat, ...)
        Schema::create('acc_branches', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Chart of Account. type & normal_balance WAJIB eksplisit (jangan turunkan dari kode).
        Schema::create('acc_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();          // kode rapi baru (1xxx aset, dst)
            $table->string('name');
            $table->enum('type', ['asset', 'liability', 'equity', 'revenue', 'expense']);
            $table->string('subtype', 40)->nullable();      // current_asset, contra_asset, cogs, other, dll
            $table->enum('normal_balance', ['debit', 'credit']);
            $table->string('legacy_code', 20)->nullable();  // referensi kode di Excel utk audit
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Header jurnal (1 transaksi = 1 journal, punya banyak lines)
        Schema::create('acc_journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('acc_branches');
            $table->date('date');
            $table->char('period', 7);                      // 'YYYY-MM' untuk laporan bulanan
            $table->string('reference')->nullable();        // No Faktur / sumber (Tiktok, Shopee, dll)
            $table->string('description')->nullable();
            $table->enum('type', ['general', 'sales', 'purchase', 'cash_in', 'cash_out', 'inventory', 'adjustment'])
                  ->default('general');
            $table->enum('status', ['draft', 'posted', 'void'])->default('draft');
            // Audit-trail balik ke sumber operasional (PO, produksi, mutasi bank, dll).
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();
            $table->index(['period', 'branch_id']);
            $table->index(['source_type', 'source_id']);
        });

        // Detail jurnal — inti double-entry. branch_id di line agar bisa split lintas cabang.
        Schema::create('acc_journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained('acc_journals')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('acc_accounts');
            $table->foreignId('branch_id')->constrained('acc_branches');
            $table->decimal('debit', 18, 2)->default(0);   // dibulatkan ke rupiah, bukan desimal panjang
            $table->decimal('credit', 18, 2)->default(0);
            $table->string('memo')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'journal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_journal_lines');
        Schema::dropIfExists('acc_journals');
        Schema::dropIfExists('acc_accounts');
        Schema::dropIfExists('acc_branches');
    }
};
