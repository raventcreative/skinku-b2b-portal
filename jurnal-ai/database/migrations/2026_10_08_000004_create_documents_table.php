<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dokumen sumber yang dibaca AI: foto struk, PDF invoice, screenshot mutasi,
 * atau teks tempel. `extraction` menyimpan hasil baca AI (JSON ternormalisasi)
 * supaya bisa direview/diedit sebelum jadi jurnal — AI tidak pernah langsung
 * posting. `content_hash` mencegah dokumen yang sama diolah & diposting dobel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);   // receipt|invoice|bank|text
            $table->string('title')->nullable();
            $table->string('original_name')->nullable();
            $table->string('disk', 20)->nullable();
            $table->string('path')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->longText('raw_text')->nullable();   // teks tempel, atau hasil ekstraksi PDF
            $table->json('extraction')->nullable();     // hasil baca AI, sudah dinormalisasi
            $table->string('model_used')->nullable();
            $table->string('status', 20)->default('uploaded'); // uploaded|extracted|failed|posted
            $table->text('error')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('extracted_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['client_id', 'content_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
