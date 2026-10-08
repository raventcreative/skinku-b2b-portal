<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Klien = entitas pemilik buku. Dua rasa dalam satu tabel:
 *   internal → brand/unit bisnis sendiri (SKINKU, Rave Tailor, …)
 *   external → klien jasa pembukuan (bisnis orang lain)
 * Pembukuannya sama-sama terisolasi; `type` hanya untuk pengelompokan & laporan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type', 20)->default('external'); // internal | external
            $table->string('legal_name')->nullable();
            $table->string('npwp', 30)->nullable();
            $table->string('contact_name')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('fiscal_year_start', 5)->default('01-01'); // MM-DD
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
