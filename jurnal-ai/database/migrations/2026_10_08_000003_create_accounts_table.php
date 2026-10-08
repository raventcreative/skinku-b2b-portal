<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chart of Account PER KLIEN. `type` & `normal_balance` eksplisit — jangan
 * pernah diturunkan dari nomor akun (penomoran bisa tidak konsisten).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('type', 20);           // asset|liability|equity|revenue|expense
            $table->string('subtype', 40)->nullable(); // cash|bank|ewallet|receivable|inventory|cogs|opex|…
            $table->string('normal_balance', 10); // debit|credit
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['client_id', 'code']);
            $table->index(['client_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
