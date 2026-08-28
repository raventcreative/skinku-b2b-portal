<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pipeline KOL — negosiasi: rate diminta (ask) → rate final, + catatan nego.
 * Melengkapi track 'affiliate' (papan pipeline ke-2) yang kolomnya sudah ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kol_pipeline_cards', function (Blueprint $table) {
            $table->unsignedBigInteger('ask_rate')->nullable()->after('followup_count');
            $table->unsignedBigInteger('final_rate')->nullable()->after('ask_rate');
            $table->text('negotiation_notes')->nullable()->after('final_rate');
        });
    }

    public function down(): void
    {
        Schema::table('kol_pipeline_cards', function (Blueprint $table) {
            $table->dropColumn(['ask_rate', 'final_rate', 'negotiation_notes']);
        });
    }
};
