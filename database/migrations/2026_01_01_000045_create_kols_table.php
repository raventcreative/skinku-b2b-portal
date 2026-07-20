<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Master KOL (Modul KOL Fase 1) — memindahkan Excel "TikTok 360° KOL Marketing".
 *
 * `level` SENGAJA bukan kolom: dia turunan murni dari followers (accessor di
 * model). Menyimpannya = dua sumber kebenaran yang bisa saling selisih saat
 * followers di-update.
 *
 * Role `kol_specialist` di-seed DI SINI, bukan di DatabaseSeeder: produksi
 * hanya menjalankan migrate --force, tidak pernah db:seed — persis pola
 * migrasi roles existing (000014) yang menanam role sistem. down() mencabutnya
 * kembali supaya rollback bersih.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kols', function (Blueprint $table) {
            $table->id();
            $table->string('tiktok_username')->unique();
            $table->string('tiktok_link')->nullable();
            $table->unsignedInteger('followers')->default(0);
            $table->string('kategori')->nullable();   // daftar pilihan di config/kol.php
            $table->string('provinsi')->nullable();
            $table->enum('status', ['prospek', 'aktif', 'hold', 'non_aktif'])->default('prospek');
            $table->text('catatan')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'kategori']);
        });

        $now = now();
        DB::table('roles')->insertOrIgnore([
            'name' => 'kol_specialist', 'label' => 'KOL Specialist', 'is_system' => false,
            'sort_order' => 10, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('kols');
        // Cabut role + override permission-nya supaya rollback tak meninggalkan
        // baris yatim di role_permissions.
        DB::table('role_permissions')->where('role', 'kol_specialist')->delete();
        DB::table('roles')->where('name', 'kol_specialist')->where('is_system', false)->delete();
    }
};
