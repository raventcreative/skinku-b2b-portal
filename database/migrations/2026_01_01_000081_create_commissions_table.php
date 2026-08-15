<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();          // penerima (upline)
            $table->foreignId('source_po_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignId('source_user_id')->nullable()->constrained('users')->nullOnDelete(); // downline pembeli
            $table->string('type', 20);           // override | join
            $table->unsignedSmallInteger('level')->default(1);
            $table->decimal('rate', 5, 2);        // persen saat hitung
            $table->decimal('base_amount', 14, 2);
            $table->decimal('amount', 14, 2);
            $table->string('status', 20)->default('saldo'); // saldo | ditarik
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index('source_po_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};
