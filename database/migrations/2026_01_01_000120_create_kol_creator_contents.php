<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Detail konten (video/LIVE) per kreator per bulan — buat halaman "klik jumlah
 * video → lihat daftarnya". Diisi saat content-sync (hanya kreator yang cocok ke
 * KOL). period = string 'Y-m-01'. Snapshot: dihapus+isi-ulang tiap sync per bulan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_creator_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kol_id')->constrained('kols')->cascadeOnDelete();
            $table->date('period');
            $table->string('type', 10);            // video | live
            $table->string('content_id', 64)->nullable();
            $table->string('title')->nullable();
            $table->unsignedBigInteger('views')->nullable(); // video only
            $table->unsignedBigInteger('gmv')->default(0);
            $table->unsignedInteger('items_sold')->default(0);
            $table->unsignedInteger('sku_orders')->default(0);
            $table->dateTime('occurred_at')->nullable();
            $table->timestamps();
            $table->index(['kol_id', 'period', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_creator_contents');
    }
};
