<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Chart of Account SKINKU — hasil pembersihan dari Financial_Skinku.xlsx.
 * Cabang di-lepas jadi dimensi; akun dibuat netral & dinomori ulang rapi.
 * legacy_code = referensi kode akun di Excel untuk audit/cross-check.
 */
class ChartOfAccountSeeder extends Seeder
{
    public function run(): void
    {
        // Cabang — semua operasional masih 1 lokasi (cabang di Excel cuma sisa template).
        // Cabang tetap disimpan sebagai DIMENSI; tambah baris kalau nanti buka cabang baru.
        if (! DB::table('acc_branches')->exists()) {
            DB::table('acc_branches')->insert([
                'code' => 'SBY-T', 'name' => 'Surabaya Timur',
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // [code, name, type, subtype, normal_balance, legacy_code]
        $accounts = [
            // ASET
            ['1001', 'Kas Shopee',                     'asset', 'cash',          'debit', '1001'],
            ['1002', 'Bank',                           'asset', 'cash',          'debit', '1002'],
            ['1003', 'Kas TikTok',                     'asset', 'cash',          'debit', '1007'],
            ['1101', 'Piutang Usaha',                  'asset', 'receivable',    'debit', '2001/2002'],
            // Persediaan dipecah mengikuti struktur app (bahan baku vs barang jadi). WIP tidak ada (produksi atomik).
            ['1201', 'Persediaan Bahan Baku',          'asset', 'inventory',     'debit', '1003'],
            ['1202', 'Persediaan Barang Jadi',         'asset', 'inventory',     'debit', '1003'],
            ['1301', 'Pembelian Cust. Dibayar Dimuka', 'asset', 'prepaid',       'debit', '1004'],
            ['1302', 'DP Persediaan Dibayar Dimuka',   'asset', 'prepaid',       'debit', '1005'],
            ['1303', 'DP Internet',                    'asset', 'prepaid',       'debit', '1006'],
            ['1304', 'Sewa Dibayar Dimuka',            'asset', 'prepaid',       'debit', '1009'],
            ['1305', 'DP Akuisisi Brand',              'asset', 'prepaid',       'debit', '1010'],
            ['1401', 'Perlengkapan Usaha',             'asset', 'fixed_asset',   'debit', '2003/2005'],
            ['1402', 'Peralatan Usaha',                'asset', 'fixed_asset',   'debit', '2004/2006'],
            ['1501', 'Akum. Penyusutan Peralatan',     'asset', 'contra_asset',  'credit', '2007/2008'],
            ['1502', 'Akum. Penyusutan Gedung',        'asset', 'contra_asset',  'credit', '1008'],

            // LIABILITAS
            ['2001', 'Hutang Usaha',                   'liability', 'current',   'credit', '3006/3007'],
            ['2002', 'Hutang Gaji Produksi',           'liability', 'current',   'credit', '3002/3004'],
            ['2003', 'Hutang Gaji Pegawai',            'liability', 'current',   'credit', '3003/3005'],
            ['2004', 'Hutang Pajak',                   'liability', 'current',   'credit', '3008/3009'],
            ['2005', 'Hutang Iklan',                   'liability', 'current',   'credit', '4005'],
            ['2006', 'Hutang Deposit Customer',        'liability', 'current',   'credit', '4006/4007'],
            ['2007', 'Hutang Lain-lain',               'liability', 'current',   'credit', '3010/4001'],
            ['2008', 'Pend. Jasa Diterima Dimuka',     'liability', 'unearned',  'credit', '5008'],
            ['2101', 'Hutang Bank',                    'liability', 'long_term', 'credit', '4002/4003'],

            // EKUITAS
            ['3001', 'Modal Usaha',                    'equity', null,           'credit', '3001'],
            ['3002', 'Prive',                          'equity', 'contra_equity', 'debit',  '4004'],
            ['3003', 'Ikhtisar Laba/Rugi',             'equity', 'closing',      'credit', '6015'],

            // PENDAPATAN
            ['4001', 'Penjualan',                      'revenue', 'sales',         'credit', '4008/4009'],
            ['4002', 'Pendapatan Lain-lain',           'revenue', 'other',         'credit', '4010/5001'],
            ['4003', 'Pendapatan Bunga',               'revenue', 'other',         'credit', '5007'],
            ['4004', 'Pendapatan Ongkir',              'revenue', 'shipping',      'credit', null],
            ['4101', 'Retur Penjualan',                'revenue', 'contra_revenue', 'debit',  '5003/5004'],
            ['4102', 'Potongan Penjualan',             'revenue', 'contra_revenue', 'debit',  '5002'],

            // HPP / COGS
            ['5001', 'Pembelian',                      'expense', 'cogs',          'debit',  '5005'],
            ['5002', 'Retur Pembelian',                'expense', 'contra_cogs',   'credit', '5006'],
            ['5003', 'Beban HPP',                      'expense', 'cogs',          'debit',  '6021/6022'],
            ['5004', 'Beban Gaji Produksi',            'expense', 'cogs',          'debit',  '5010'],

            // BEBAN OPERASIONAL
            ['6001', 'Beban Iklan / Promosi',          'expense', 'operating',     'debit',  '5009'],
            ['6002', 'Beban Gaji Pegawai',             'expense', 'operating',     'debit',  '6001/6003'],
            ['6003', 'Beban Sewa',                     'expense', 'operating',     'debit',  '6004/6005'],
            ['6004', 'Beban Administrasi',             'expense', 'operating',     'debit',  '6006'],
            ['6005', 'Beban Biaya E-commerce',         'expense', 'operating',     'debit',  '6007'],
            ['6006', 'Beban Listrik / Air',            'expense', 'operating',     'debit',  '6008/6009'],
            ['6007', 'Beban Ongkos Kirim',             'expense', 'operating',     'debit',  '6013/6014'],
            ['6008', 'Beban Operasional',              'expense', 'operating',     'debit',  '6023/6024'],
            ['6009', 'Beban Perlengkapan Toko',        'expense', 'operating',     'debit',  '6017'],
            ['6010', 'Beban Penyusutan Peralatan',     'expense', 'operating',     'debit',  '6018'],
            ['6011', 'Beban Penyusutan Gedung',        'expense', 'operating',     'debit',  '6019'],
            ['6012', 'Beban Sample',                   'expense', 'operating',     'debit',  '6020'],
            ['6013', 'Beban Lain-lain',                'expense', 'operating',     'debit',  '6011'],

            // NON-OPERASIONAL
            ['7001', 'Beban Bunga',                    'expense', 'non_operating', 'debit',  '6010/6012'], // 6012=Beban Hutang Bank (bunga pinjaman, dipakai sejak Apr)
            ['7002', 'Beban Pajak',                    'expense', 'tax',           'debit',  '6016'],
        ];

        // Idempotent SYNC: perbarui akun yang sudah ada (by code) tanpa menyentuh
        // is_active (hormati akun yg dinonaktifkan user), sisipkan yg belum ada.
        // Ini yg membuat penyempurnaan COA (mis. legacy_code baru) bisa ter-deploy.
        foreach ($accounts as $a) {
            $attrs = [
                'name' => $a[1], 'type' => $a[2], 'subtype' => $a[3],
                'normal_balance' => $a[4], 'legacy_code' => $a[5], 'updated_at' => now(),
            ];
            if (DB::table('acc_accounts')->where('code', $a[0])->exists()) {
                DB::table('acc_accounts')->where('code', $a[0])->update($attrs);
            } else {
                DB::table('acc_accounts')->insert($attrs + ['code' => $a[0], 'is_active' => true, 'created_at' => now()]);
            }
        }
    }
}
