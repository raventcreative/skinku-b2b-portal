<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Akun Excel 6002 "Beban Gaji Affiliate" belum punya padanan di COA app →
 * baris impor jurnalnya dilewati. Tambah akun ber-legacy 6002 agar auto-terpetakan.
 * Kode dipilih dari 60xx yang masih kosong (mulai 6014) — user bisa saja sudah
 * membuat akun manual di kode itu lewat menu COA.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sudahAda = DB::table('acc_accounts')->pluck('legacy_code')
            ->contains(fn ($l) => in_array('6002', array_map('trim', explode('/', (string) $l)), true));
        if ($sudahAda) {
            return;
        }

        $code = 6014;
        while (DB::table('acc_accounts')->where('code', (string) $code)->exists()) {
            $code++;
        }

        DB::table('acc_accounts')->insert([
            'code' => (string) $code, 'name' => 'Beban Gaji / Komisi Affiliate',
            'type' => 'expense', 'subtype' => 'operating', 'normal_balance' => 'debit',
            'legacy_code' => '6002', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('acc_accounts')->where('legacy_code', '6002')
            ->where('name', 'Beban Gaji / Komisi Affiliate')
            ->whereNotExists(fn ($q) => $q->from('acc_journal_lines')->whereColumn('acc_journal_lines.account_id', 'acc_accounts.id'))
            ->delete();
    }
};
