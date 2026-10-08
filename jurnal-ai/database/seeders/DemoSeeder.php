<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Document;
use App\Models\User;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\JournalPoster;
use App\Services\Intake\JournalDrafter;
use Illuminate\Database\Seeder;

/**
 * Data contoh untuk mencoba sistem tanpa API key: satu bulan pembukuan SKINKU
 * + satu dokumen yang "sudah dibaca AI" supaya layar review bisa dicoba.
 *
 * HANYA untuk lokal/demo. Jangan jalankan di server produksi:
 *   php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoSeeder tidak boleh jalan di produksi.');

            return;
        }

        $admin = User::where('role', User::ROLE_ADMIN)->first()
            ?? User::create([
                'name' => 'Admin', 'email' => 'admin@jurnal.test', 'password' => 'password123',
                'role' => User::ROLE_ADMIN, 'is_active' => true,
            ]);

        $client = Client::firstOrCreate(
            ['slug' => 'skinku'],
            ['name' => 'SKINKU', 'type' => Client::TYPE_INTERNAL, 'is_active' => true],
        );
        app(ChartOfAccounts::class)->seedFor($client);

        if ($client->journals()->exists()) {
            $this->command?->warn('Data demo sudah ada — dilewati.');

            return;
        }

        $poster = app(JournalPoster::class);
        $akun = fn (string $code) => $client->accounts()->where('code', $code)->value('id');
        $period = now()->format('Y-m');

        // [tanggal, keterangan, jenis, [[kode, debit, kredit, memo], …]]
        $jurnal = [
            ["{$period}-01", 'Saldo awal: setoran modal & persediaan', 'adjustment', [
                ['1102', 85_000_000, 0, 'Saldo bank awal'],
                ['1301', 82_000_000, 0, 'Persediaan barang jadi'],
                ['3101', 0, 167_000_000, 'Modal pemilik'],
            ]],
            ["{$period}-03", 'Penjualan TikTok Shop minggu 1', 'sale', [
                ['1104', 42_500_000, 0, 'Saldo TikTok Shop'],
                ['4101', 0, 42_500_000, 'Penjualan marketplace'],
            ]],
            ["{$period}-03", 'HPP penjualan minggu 1', 'sale', [
                ['5101', 16_800_000, 0, 'HPP'],
                ['1301', 0, 16_800_000, 'Persediaan keluar'],
            ]],
            ["{$period}-05", 'Fee marketplace TikTok Shop', 'expense', [
                ['6108', 4_675_000, 0, 'Fee & komisi TikTok Shop'],
                ['1104', 0, 4_675_000, 'Dipotong dari saldo'],
            ]],
            ["{$period}-07", 'Iklan Meta + TikTok Ads', 'expense', [
                ['6102', 12_350_000, 0, 'Meta Ads & TikTok Ads'],
                ['1102', 0, 12_350_000, 'Autodebet kartu'],
            ]],
            ["{$period}-10", 'Pembelian bahan baku & kemasan (masuk persediaan)', 'purchase', [
                ['1302', 24_600_000, 0, 'Bahan baku sabun + botol & label'],
                ['2101', 0, 24_600_000, 'Hutang ke supplier (tempo 30 hari)'],
            ]],
            ["{$period}-12", 'Gaji tim Oktober', 'expense', [
                ['6101', 15_700_000, 0, 'Gaji 6 orang'],
                ['1102', 0, 15_700_000, 'Transfer payroll'],
            ]],
            ["{$period}-15", 'Komisi affiliate & KOL', 'expense', [
                ['6103', 8_900_000, 0, 'Fee 24 affiliate'],
                ['1102', 0, 8_900_000, 'Transfer'],
            ]],
            ["{$period}-16", 'Penjualan Shopee + reseller', 'sale', [
                ['1104', 31_200_000, 0, 'Saldo Shopee'],
                ['1201', 8_500_000, 0, 'Piutang reseller'],
                ['4101', 0, 39_700_000, 'Penjualan'],
            ]],
            ["{$period}-16", 'HPP penjualan minggu 3', 'sale', [
                ['5101', 15_600_000, 0, 'HPP'],
                ['1301', 0, 15_600_000, 'Persediaan keluar'],
            ]],
            ["{$period}-18", 'Ongkir & packing', 'expense', [
                ['6106', 3_240_000, 0, 'JNE, J&T, SiCepat'],
                ['1101', 0, 3_240_000, 'Kas kecil'],
            ]],
            ["{$period}-20", 'Sewa gudang + listrik', 'expense', [
                ['6104', 2_500_000, 0, 'Sewa gudang'],
                ['6105', 1_850_000, 0, 'Listrik & internet'],
                ['1102', 0, 4_350_000, 'Transfer'],
            ]],
            ["{$period}-22", 'Produksi konten & langganan AI', 'expense', [
                ['6116', 4_500_000, 0, 'Videografer & editor'],
                ['6114', 1_120_000, 0, 'ChatGPT, Claude, Canva'],
                ['1102', 0, 5_620_000, 'Kartu kredit'],
            ]],
            ["{$period}-24", 'Penjualan TikTok Shop minggu 4 + live commerce', 'sale', [
                ['1104', 58_300_000, 0, 'Saldo TikTok Shop'],
                ['1101', 7_200_000, 0, 'Penjualan langsung tunai'],
                ['4101', 0, 65_500_000, 'Penjualan'],
            ]],
            ["{$period}-24", 'HPP penjualan minggu 4', 'sale', [
                ['5101', 24_600_000, 0, 'HPP'],
                ['1301', 0, 24_600_000, 'Persediaan keluar'],
            ]],
            ["{$period}-25", 'Retur penjualan marketplace', 'sale', [
                ['4104', 2_150_000, 0, 'Retur & pembatalan'],
                ['1104', 0, 2_150_000, 'Potongan saldo'],
            ]],
            ["{$period}-28", 'Angsuran bank: pokok + bunga', 'bank', [
                ['2102', 5_800_000, 0, 'Cicilan pokok'],
                ['7101', 1_119_217, 0, 'Bunga pinjaman'],
                ['1102', 0, 6_919_217, 'Autodebet'],
            ]],
            ["{$period}-28", 'Biaya administrasi bank', 'bank', [
                ['7102', 65_000, 0, 'Admin & biaya transfer'],
                ['1102', 0, 65_000, 'Potongan rekening'],
            ]],
        ];

        foreach ($jurnal as [$date, $description, $type, $lines]) {
            $poster->record([
                'client_id' => $client->id,
                'date' => $date,
                'description' => $description,
                'type' => $type,
                'created_by' => $admin->id,
            ], array_map(fn ($l) => [
                'account_id' => $akun($l[0]),
                'debit' => $l[1],
                'credit' => $l[2],
                'memo' => $l[3],
            ], $lines));
        }

        // Dokumen contoh yang "sudah dibaca AI" tapi BELUM diposting — supaya
        // layar review bisa langsung dicoba tanpa API key.
        $client->documents()->create([
            'kind' => Document::KIND_RECEIPT,
            'title' => 'Struk belanja kemasan (contoh)',
            'original_name' => 'struk-kemasan.jpg',
            'mime_type' => 'image/jpeg',
            'raw_text' => "TOKO PLASTIK JAYA\n02/".now()->format('m/Y')."\nBotol pump 500ml 200pcs x 3.500 = 700.000\nLabel stiker 200pcs x 250 = 50.000\nKardus 20pcs x 4.000 = 80.000\nDiskon 30.000\nTOTAL 800.000\nTUNAI",
            'status' => Document::STATUS_EXTRACTED,
            'model_used' => 'contoh-demo',
            'extracted_at' => now(),
            'uploaded_by' => $admin->id,
            'extraction' => [
                'doc_type' => 'receipt',
                'flow' => 'expense',
                'vendor' => 'Toko Plastik Jaya',
                'document_number' => null,
                'document_date' => now()->format('Y-m').'-02',
                'due_date' => null,
                'payment_method' => 'cash',
                'currency' => 'IDR',
                'subtotal' => 830000.0,
                'discount' => 30000.0,
                'tax' => 0.0,
                'total' => 800000.0,
                'items' => [
                    ['description' => 'Botol pump 500ml', 'qty' => 200.0, 'unit' => 'pcs', 'unit_price' => 3500.0, 'amount' => 700000.0, 'account_code' => '5104', 'account_reason' => 'Kemasan primer produk → masuk HPP, bukan beban operasional.', 'confidence' => 0.94],
                    ['description' => 'Label stiker', 'qty' => 200.0, 'unit' => 'pcs', 'unit_price' => 250.0, 'amount' => 50000.0, 'account_code' => '5104', 'account_reason' => 'Label produk = komponen kemasan.', 'confidence' => 0.9],
                    ['description' => 'Kardus pengiriman', 'qty' => 20.0, 'unit' => 'pcs', 'unit_price' => 4000.0, 'amount' => 80000.0, 'account_code' => '6107', 'account_reason' => 'Kardus kirim bisa masuk perlengkapan atau ongkir — perlu dipastikan.', 'confidence' => 0.58],
                ],
                'transactions' => [],
                'notes' => 'Struk kasir, tulisan jelas.',
                'warnings' => ['Kardus pengiriman: AI ragu antara Beban Perlengkapan Kantor dan Beban Transportasi & Ongkir — tentukan sesuai kebiasaan pembukuan klien ini.'],
            ],
        ]);

        $this->command?->info('Data demo SKINKU dibuat: '.count($jurnal).' jurnal + 1 dokumen menunggu review.');
    }
}
