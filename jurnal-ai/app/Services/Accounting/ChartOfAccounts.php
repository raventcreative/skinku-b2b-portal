<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Client;

/**
 * Template COA standar UMKM/brand Indonesia. Dipasang otomatis saat klien baru
 * dibuat, lalu bebas diedit per klien. Nomor akun: 1xxx aset · 2xxx liabilitas ·
 * 3xxx ekuitas · 4xxx pendapatan · 5xxx HPP · 6xxx beban operasional ·
 * 7xxx non-operasional.
 *
 * `subtype` bukan hiasan — dipakai mesin:
 *   cash|bank|ewallet → kandidat akun pembayaran (sisi kredit saat belanja),
 *   payable           → lawan jurnal kalau belanja belum dibayar (hutang),
 *   tax_in            → PPN masukan dari invoice,
 *   opex|cogs         → sasaran default rincian biaya hasil baca AI.
 */
class ChartOfAccounts
{
    /** Akun default yang dipakai mesin kalau AI tidak yakin. */
    public const DEFAULT_EXPENSE = '6190';

    public const DEFAULT_CASH = '1101';

    public const DEFAULT_PAYABLE = '2101';

    public const DEFAULT_TAX_IN = '1402';

    public const DEFAULT_REVENUE = '4101';

    /**
     * @return array<int,array{code:string,name:string,type:string,subtype:?string}>
     */
    public static function template(): array
    {
        return [
            // ── 1xxx Aset ────────────────────────────────────────────────────
            ['code' => '1101', 'name' => 'Kas', 'type' => 'asset', 'subtype' => 'cash'],
            ['code' => '1102', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'bank'],
            ['code' => '1103', 'name' => 'E-Wallet (QRIS/OVO/GoPay/Dana)', 'type' => 'asset', 'subtype' => 'ewallet'],
            ['code' => '1104', 'name' => 'Saldo Marketplace', 'type' => 'asset', 'subtype' => 'ewallet'],
            ['code' => '1201', 'name' => 'Piutang Usaha', 'type' => 'asset', 'subtype' => 'receivable'],
            ['code' => '1301', 'name' => 'Persediaan Barang Jadi', 'type' => 'asset', 'subtype' => 'inventory'],
            ['code' => '1302', 'name' => 'Persediaan Bahan Baku', 'type' => 'asset', 'subtype' => 'inventory'],
            ['code' => '1401', 'name' => 'Biaya Dibayar Dimuka', 'type' => 'asset', 'subtype' => 'prepaid'],
            ['code' => '1402', 'name' => 'PPN Masukan', 'type' => 'asset', 'subtype' => 'tax_in'],
            ['code' => '1501', 'name' => 'Peralatan & Inventaris', 'type' => 'asset', 'subtype' => 'fixed_asset'],
            ['code' => '1502', 'name' => 'Akumulasi Penyusutan Peralatan', 'type' => 'asset', 'subtype' => 'contra_asset'],

            // ── 2xxx Liabilitas ─────────────────────────────────────────────
            ['code' => '2101', 'name' => 'Hutang Usaha', 'type' => 'liability', 'subtype' => 'payable'],
            ['code' => '2102', 'name' => 'Hutang Bank', 'type' => 'liability', 'subtype' => 'loan'],
            ['code' => '2103', 'name' => 'Hutang Pajak', 'type' => 'liability', 'subtype' => 'tax_out'],
            ['code' => '2104', 'name' => 'Beban Yang Masih Harus Dibayar', 'type' => 'liability', 'subtype' => 'accrued'],
            ['code' => '2105', 'name' => 'Hutang Lain-lain', 'type' => 'liability', 'subtype' => 'payable'],

            // ── 3xxx Ekuitas ────────────────────────────────────────────────
            ['code' => '3101', 'name' => 'Modal Pemilik', 'type' => 'equity', 'subtype' => 'capital'],
            ['code' => '3102', 'name' => 'Prive / Pengambilan Pemilik', 'type' => 'equity', 'subtype' => 'drawing'],
            ['code' => '3201', 'name' => 'Laba Ditahan', 'type' => 'equity', 'subtype' => 'retained'],

            // ── 4xxx Pendapatan ─────────────────────────────────────────────
            ['code' => '4101', 'name' => 'Penjualan', 'type' => 'revenue', 'subtype' => 'sales'],
            ['code' => '4102', 'name' => 'Pendapatan Jasa', 'type' => 'revenue', 'subtype' => 'service'],
            ['code' => '4103', 'name' => 'Diskon Penjualan', 'type' => 'revenue', 'subtype' => 'contra_revenue'],
            ['code' => '4104', 'name' => 'Retur Penjualan', 'type' => 'revenue', 'subtype' => 'contra_revenue'],
            ['code' => '4901', 'name' => 'Pendapatan Lain-lain', 'type' => 'revenue', 'subtype' => 'other'],

            // ── 5xxx Harga Pokok ────────────────────────────────────────────
            ['code' => '5101', 'name' => 'Harga Pokok Penjualan', 'type' => 'expense', 'subtype' => 'cogs'],
            ['code' => '5102', 'name' => 'Pembelian Bahan Baku', 'type' => 'expense', 'subtype' => 'cogs'],
            ['code' => '5103', 'name' => 'Beban Produksi & Maklon', 'type' => 'expense', 'subtype' => 'cogs'],
            ['code' => '5104', 'name' => 'Beban Kemasan', 'type' => 'expense', 'subtype' => 'cogs'],

            // ── 6xxx Beban Operasional ──────────────────────────────────────
            ['code' => '6101', 'name' => 'Beban Gaji & Upah', 'type' => 'expense', 'subtype' => 'opex'],
            ['code' => '6102', 'name' => 'Beban Iklan & Promosi', 'type' => 'expense', 'subtype' => 'opex'],
            ['code' => '6103', 'name' => 'Beban Komisi Affiliate & KOL', 'type' => 'expense', 'subtype' => 'opex'],
            ['code' => '6104', 'name' => 'Beban Sewa', 'type' => 'expense', 'subtype' => 'opex'],
            ['code' => '6105', 'name' => 'Beban Listrik, Air & Internet', 'type' => 'expense', 'subtype' => 'opex'],
            ['code' => '6106', 'name' => 'Beban Transportasi & Ongkir', 'type' => 'expense', 'subtype' => 'opex'],
            ['code' => '6107', 'name' => 'Beban Perlengkapan Kantor', 'type' => 'expense', 'subtype' => 'opex'],
            ['code' => '6108', 'name' => 'Beban Marketplace & Payment Gateway', 'type' => 'expense', 'subtype' => 'opex'],
            ['code' => '6109', 'name' => 'Beban Jasa Profesional', 'type' => 'expense', 'subtype' => 'opex'],
            ['code' => '6110', 'name' => 'Beban Perjalanan & Akomodasi', 'type' => 'expense', 'subtype' => 'opex'],
            ['code' => '6111', 'name' => 'Beban Konsumsi & Entertainment', 'type' => 'expense', 'subtype' => 'opex'],
            ['code' => '6112', 'name' => 'Beban Pemeliharaan & Perbaikan', 'type' => 'expense', 'subtype' => 'opex'],
            ['code' => '6113', 'name' => 'Beban Penyusutan', 'type' => 'expense', 'subtype' => 'opex'],
            ['code' => '6114', 'name' => 'Beban Langganan Software & AI', 'type' => 'expense', 'subtype' => 'opex'],
            ['code' => '6115', 'name' => 'Beban Pelatihan & Pengembangan', 'type' => 'expense', 'subtype' => 'opex'],
            ['code' => '6116', 'name' => 'Beban Produksi Konten', 'type' => 'expense', 'subtype' => 'opex'],
            ['code' => '6190', 'name' => 'Beban Operasional Lain-lain', 'type' => 'expense', 'subtype' => 'opex'],

            // ── 7xxx Non-operasional ────────────────────────────────────────
            ['code' => '7101', 'name' => 'Beban Bunga', 'type' => 'expense', 'subtype' => 'non_operating'],
            ['code' => '7102', 'name' => 'Beban Administrasi Bank', 'type' => 'expense', 'subtype' => 'non_operating'],
            ['code' => '7103', 'name' => 'Beban Pajak', 'type' => 'expense', 'subtype' => 'non_operating'],
            ['code' => '7901', 'name' => 'Beban Lain-lain', 'type' => 'expense', 'subtype' => 'non_operating'],
        ];
    }

    /** Saldo normal diturunkan dari tipe, KECUALI akun kontra yang dibalik. */
    public static function normalBalanceFor(string $code, string $type): string
    {
        // Kontra-akun: tipenya ikut induk tapi saldo normalnya berlawanan.
        $contra = ['1502' => 'credit', '4103' => 'debit', '4104' => 'debit', '3102' => 'debit'];
        if (isset($contra[$code])) {
            return $contra[$code];
        }

        return in_array($type, Account::DEBIT_TYPES, true) ? 'debit' : 'credit';
    }

    /**
     * Pasang COA template untuk klien. Idempoten — akun yang kodenya sudah ada
     * dilewati, jadi aman dipanggil ulang pada klien lama (mis. setelah template
     * ditambah akun baru).
     *
     * @return int jumlah akun yang benar-benar baru dibuat
     */
    public function seedFor(Client $client): int
    {
        $existing = $client->accounts()->pluck('code')->all();
        $existing = array_flip($existing);
        $created = 0;

        foreach (self::template() as $row) {
            if (isset($existing[$row['code']])) {
                continue;
            }

            $client->accounts()->create([
                'code' => $row['code'],
                'name' => $row['name'],
                'type' => $row['type'],
                'subtype' => $row['subtype'],
                'normal_balance' => self::normalBalanceFor($row['code'], $row['type']),
                'is_active' => true,
            ]);
            $created++;
        }

        return $created;
    }
}
