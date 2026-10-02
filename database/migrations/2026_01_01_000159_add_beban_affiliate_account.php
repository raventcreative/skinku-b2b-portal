<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Akun Excel 6002 "Beban Gaji Affiliate" belum punya padanan di COA app →
 * baris impor jurnalnya dilewati. Tambah 6014 (legacy 6002) agar auto-terpetakan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('acc_accounts')->where('code', '6014')->exists()) {
            return;
        }

        DB::table('acc_accounts')->insert([
            'code' => '6014', 'name' => 'Beban Gaji / Komisi Affiliate',
            'type' => 'expense', 'subtype' => 'operating', 'normal_balance' => 'debit',
            'legacy_code' => '6002', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('acc_accounts')->where('code', '6014')->whereNotExists(
            fn ($q) => $q->from('acc_journal_lines')->whereColumn('acc_journal_lines.account_id', 'acc_accounts.id')
        )->delete();
    }
};
