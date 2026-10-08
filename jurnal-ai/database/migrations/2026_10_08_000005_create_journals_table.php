<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->string('period', 7);  // YYYY-MM, diturunkan dari date
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->string('type', 30)->default('general'); // general|expense|purchase|sale|bank|adjustment
            $table->string('status', 10)->default('posted'); // draft|posted|void
            // Sidik jari transaksi — menahan posting dobel dari dokumen/baris mutasi yang sama.
            $table->string('fingerprint', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'period', 'status']);
            $table->index(['client_id', 'date']);
            $table->index('fingerprint');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journals');
    }
};
