<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perkaya kol_deals mengikuti model deal Iyuro: tipe deal, biaya lain,
 * deliverables/jadwal/hak pakai, rincian DP + catatan bayar, catatan internal.
 * Semua nullable/berdefault → aman untuk deal lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kol_deals', function (Blueprint $table) {
            $table->string('deal_type', 20)->default('paid')->after('jenis'); // paid/barter/affiliate_only
            $table->unsignedInteger('other_cost')->default(0)->after('total_biaya'); // biaya lain (finance)
            $table->unsignedTinyInteger('dp_percent')->default(0)->after('status_bayar'); // 0/1-99 (finance)
            $table->string('payment_note', 255)->nullable()->after('atas_nama'); // bukti/catatan bayar (finance)
            $table->text('deliverables')->nullable()->after('link_mou');
            $table->date('posting_deadline')->nullable()->after('deliverables');
            $table->string('usage_rights', 255)->nullable()->after('posting_deadline');
            $table->text('internal_notes')->nullable()->after('usage_rights');
        });
    }

    public function down(): void
    {
        Schema::table('kol_deals', function (Blueprint $table) {
            $table->dropColumn([
                'deal_type', 'other_cost', 'dp_percent', 'payment_note',
                'deliverables', 'posting_deadline', 'usage_rights', 'internal_notes',
            ]);
        });
    }
};
