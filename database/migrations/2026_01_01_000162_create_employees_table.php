<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu HR Fase 1 — database karyawan + checklist onboarding (spec docs/superpowers/specs/2026-10-10-hr-design.md).
 * Kolom identitas disimpan TERENKRIPSI (cast 'encrypted' di App\Models\Employee) → bertipe text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 20)->nullable()->unique();           // KRY-0001, diisi otomatis setelah dibuat
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete(); // akun portal staf (opsional)
            $table->string('name');
            $table->string('position')->nullable();
            $table->string('department', 60)->nullable();
            $table->string('employment_type', 20)->default('percobaan');
            $table->string('status', 20)->default('aktif');
            $table->date('join_date')->nullable();
            $table->date('probation_end')->nullable();
            $table->date('contract_end')->nullable();
            $table->date('resign_date')->nullable();
            $table->string('resign_reason')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('gender', 1)->nullable();
            $table->string('ptkp_status', 5)->nullable();                 // TK/0…K/3 (PPh 21, Fase 3 payroll)
            $table->text('nik')->nullable();
            $table->text('npwp')->nullable();
            $table->text('address')->nullable();
            $table->string('bank_name', 60)->nullable();
            $table->text('bank_account')->nullable();
            $table->text('bank_account_name')->nullable();
            $table->text('bpjs_kesehatan')->nullable();
            $table->text('bpjs_ketenagakerjaan')->nullable();
            $table->text('emergency_contact')->nullable();
            $table->json('onboarding')->nullable();                       // {item: {selesai, oleh}}
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'department']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
