<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu HR Fase 3 — Payroll (spec docs/superpowers/specs/2026-10-10-hr-design.md): komponen gaji per karyawan
 * (+ saldo awal tahun dari luar portal), run bulanan draf → dikunci, dan baris slip (angka beku saat dikunci).
 * Semua nominal rupiah bulat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained('employees')->cascadeOnDelete();
            $table->unsignedBigInteger('base_salary')->default(0);        // gaji pokok
            $table->unsignedBigInteger('fixed_allowance')->default(0);    // tunjangan tetap (ikut dasar upah BPJS)
            $table->boolean('bpjs_kesehatan')->default(true);
            $table->boolean('bpjs_tk')->default(true);                    // JHT + JKK + JKM
            $table->boolean('bpjs_jp')->default(true);                    // Jaminan Pensiun
            $table->string('cost_group', 12)->default('operasional');     // operasional | produksi (akun beban & utang gaji)
            // Saldo awal tahun: bulan yang digaji di luar portal (dasar hitung ulang PPh 21 setahun di masa pajak terakhir).
            $table->unsignedSmallInteger('opening_year')->nullable();
            $table->unsignedTinyInteger('opening_months')->default(0);
            $table->unsignedBigInteger('opening_bruto')->default(0);
            $table->unsignedBigInteger('opening_iuran')->default(0);      // JHT 2% + JP 1% bagian karyawan
            $table->unsignedBigInteger('opening_pph21')->default(0);
            $table->timestamps();
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->date('period')->unique();                             // tanggal 1 bulan gaji
            $table->string('status', 10)->default('draf');                // draf | dikunci
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('journal_id')->nullable()->constrained('acc_journals')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees');
            // Potret data karyawan saat dihitung (slip tetap sama walau data karyawan berubah).
            $table->string('employee_name');
            $table->string('position', 100)->nullable();
            $table->string('ptkp_status', 5);
            $table->char('ter_category', 1);
            $table->string('cost_group', 12);
            $table->boolean('bpjs_kesehatan');
            $table->boolean('bpjs_tk');
            $table->boolean('bpjs_jp');
            // Penghasilan & potongan.
            $table->unsignedBigInteger('base_salary')->default(0);
            $table->unsignedBigInteger('fixed_allowance')->default(0);
            $table->unsignedBigInteger('overtime')->default(0);
            $table->unsignedBigInteger('bonus')->default(0);              // bonus / THR / insentif
            $table->unsignedBigInteger('kasbon')->default(0);
            // BPJS.
            $table->unsignedBigInteger('kes_company')->default(0);
            $table->unsignedBigInteger('kes_employee')->default(0);
            $table->unsignedBigInteger('jht_company')->default(0);
            $table->unsignedBigInteger('jht_employee')->default(0);
            $table->unsignedBigInteger('jp_company')->default(0);
            $table->unsignedBigInteger('jp_employee')->default(0);
            $table->unsignedBigInteger('jkk')->default(0);
            $table->unsignedBigInteger('jkm')->default(0);
            // PPh 21: TER bulanan, atau hitung ulang setahun (Desember / bulan terakhir bekerja) — bisa negatif (lebih potong).
            $table->unsignedBigInteger('bruto')->default(0);
            $table->unsignedSmallInteger('ter_rate')->default(0);         // basis poin (1% = 100)
            $table->boolean('annual')->default(false);
            $table->json('tax_detail')->nullable();
            $table->bigInteger('pph21_auto')->default(0);
            $table->bigInteger('pph21_override')->nullable();
            $table->bigInteger('pph21')->default(0);
            $table->bigInteger('net_pay')->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->unique(['payroll_run_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_items');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('payroll_profiles');
    }
};
