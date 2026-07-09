<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Template transaksi (preset jurnal) — mempercepat input jurnal berulang.
 * Tiap template punya beberapa baris; account_id NULL = akun dipilih saat input
 * (mis. akun Kas/Bank sumber pembayaran yang sering beda).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('acc_template_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('acc_template_id')->constrained('acc_templates')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('acc_accounts')->nullOnDelete(); // null = pilih saat input
            $table->enum('side', ['debit', 'credit']);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_template_lines');
        Schema::dropIfExists('acc_templates');
    }
};
