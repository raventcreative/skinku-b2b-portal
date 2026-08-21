<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kol_screenings', function (Blueprint $table) {
            // GMV aktual KOL (Rp) — input MANUAL, beda dari accessor gmv_estimate
            // (yang dihitung dari median views). Nullable: kadang belum tahu.
            $table->unsignedBigInteger('gmv')->nullable()->after('ratecard');
            // Benefit/deliverable yang didapat dari ratecard (mis. "2 video + 1 live 30 mnt").
            $table->string('benefit', 500)->nullable()->after('gmv');
        });
    }

    public function down(): void
    {
        Schema::table('kol_screenings', function (Blueprint $table) {
            $table->dropColumn(['gmv', 'benefit']);
        });
    }
};
