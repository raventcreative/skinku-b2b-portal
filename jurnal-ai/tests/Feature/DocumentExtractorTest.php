<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Document;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Intake\DocumentExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Normalisasi hasil baca AI. Ini pagar terakhir sebelum angka model menyentuh
 * jurnal — model boleh ngawur, keluaran normalize() tidak boleh.
 */
class DocumentExtractorTest extends TestCase
{
    use RefreshDatabase;

    private DocumentExtractor $extractor;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = app(DocumentExtractor::class);
        $this->client = Client::create(['name' => 'Klien Uji', 'type' => Client::TYPE_EXTERNAL]);
        app(ChartOfAccounts::class)->seedFor($this->client);
    }

    public function test_angka_bergaya_rupiah_dicuci_jadi_float(): void
    {
        $out = $this->extractor->normalize([
            'flow' => 'expense',
            'total' => 'Rp 1.250.000',
            'tax' => '125.000',
            'items' => [
                ['description' => 'Iklan Meta', 'amount' => 'Rp 1.000.000', 'account_code' => '6102'],
                ['description' => 'Ongkir', 'amount' => '250.000', 'account_code' => '6106'],
            ],
        ], $this->client);

        $this->assertSame(1250000.0, $out['total']);
        $this->assertSame(125000.0, $out['tax']);
        $this->assertSame(1000000.0, $out['items'][0]['amount']);
        $this->assertSame(250000.0, $out['items'][1]['amount']);
    }

    public function test_kode_akun_karangan_dialihkan_ke_akun_default_dengan_peringatan(): void
    {
        $out = $this->extractor->normalize([
            'flow' => 'expense',
            'items' => [['description' => 'Entah apa', 'amount' => 50000, 'account_code' => '9999']],
        ], $this->client);

        $this->assertSame(ChartOfAccounts::DEFAULT_EXPENSE, $out['items'][0]['account_code']);
        $this->assertNotEmpty($out['warnings']);
        $this->assertStringContainsString('9999', implode(' ', $out['warnings']));
    }

    public function test_kode_akun_kosong_juga_dialihkan(): void
    {
        $out = $this->extractor->normalize([
            'flow' => 'income',
            'items' => [['description' => 'Penjualan', 'amount' => 50000]],
        ], $this->client);

        $this->assertSame(ChartOfAccounts::DEFAULT_REVENUE, $out['items'][0]['account_code']);
    }

    public function test_amount_dihitung_dari_qty_kali_harga_satuan_kalau_kosong(): void
    {
        $out = $this->extractor->normalize([
            'items' => [[
                'description' => 'Botol 500ml', 'qty' => 200, 'unit_price' => 3500,
                'amount' => null, 'account_code' => '5104',
            ]],
        ], $this->client);

        $this->assertSame(700000.0, $out['items'][0]['amount']);
    }

    public function test_baris_bernilai_nol_dibuang(): void
    {
        $out = $this->extractor->normalize([
            'items' => [
                ['description' => 'Nyata', 'amount' => 1000, 'account_code' => '6190'],
                ['description' => 'Kosong', 'amount' => 0, 'account_code' => '6190'],
                ['description' => 'Null', 'amount' => null, 'account_code' => '6190'],
                'bukan array',
            ],
        ], $this->client);

        $this->assertCount(1, $out['items']);
    }

    public function test_rincian_kosong_tapi_total_ada_dibuatkan_satu_baris_payung(): void
    {
        $out = $this->extractor->normalize([
            'vendor' => 'Toko Plastik Jaya',
            'total' => 450000,
            'items' => [],
        ], $this->client);

        $this->assertCount(1, $out['items']);
        $this->assertSame(450000.0, $out['items'][0]['amount']);
        $this->assertSame('Toko Plastik Jaya', $out['items'][0]['description']);
        $this->assertStringContainsString('tidak terbaca', implode(' ', $out['warnings']));
    }

    public function test_rincian_yang_tak_cocok_total_memunculkan_peringatan(): void
    {
        $out = $this->extractor->normalize([
            'total' => 1000000,
            'items' => [['description' => 'Barang', 'amount' => 600000, 'account_code' => '6190']],
        ], $this->client);

        $this->assertStringContainsString('tidak cocok total', implode(' ', $out['warnings']));
    }

    public function test_selisih_pembulatan_satu_rupiah_tidak_dianggap_masalah(): void
    {
        $out = $this->extractor->normalize([
            'document_date' => '2026-10-08',
            'total' => 1000000,
            'items' => [['description' => 'Barang', 'amount' => 999999.5, 'account_code' => '6190']],
        ], $this->client);

        $this->assertSame([], $out['warnings']);
    }

    public function test_rincian_plus_pajak_minus_diskon_dicek_terhadap_total(): void
    {
        $out = $this->extractor->normalize([
            'document_date' => '2026-10-08',
            'total' => 1050000,
            'tax' => 100000,
            'discount' => 50000,
            'items' => [['description' => 'Barang', 'amount' => 1000000, 'account_code' => '6190']],
        ], $this->client);

        $this->assertSame([], $out['warnings'], 'Kombinasi yang pas tidak boleh diberi peringatan.');
    }

    public function test_tanggal_ngawur_ditolak_dan_default_hari_ini(): void
    {
        $out = $this->extractor->normalize([
            'document_date' => '1970-01-01',
            'items' => [['description' => 'X', 'amount' => 1000, 'account_code' => '6190']],
        ], $this->client);

        $this->assertSame(now()->toDateString(), $out['document_date']);
        $this->assertStringContainsString('Tanggal dokumen tidak terbaca', implode(' ', $out['warnings']));
    }

    public function test_tanggal_valid_dipertahankan(): void
    {
        $out = $this->extractor->normalize([
            'document_date' => '2026-04-03',
            'items' => [['description' => 'X', 'amount' => 1000, 'account_code' => '6190']],
        ], $this->client);

        $this->assertSame('2026-04-03', $out['document_date']);
    }

    public function test_confidence_persen_dinormalkan_ke_nol_sampai_satu(): void
    {
        $out = $this->extractor->normalize([
            'items' => [
                ['description' => 'A', 'amount' => 1, 'account_code' => '6190', 'confidence' => 85],
                ['description' => 'B', 'amount' => 1, 'account_code' => '6190', 'confidence' => 0.4],
                ['description' => 'C', 'amount' => 1, 'account_code' => '6190', 'confidence' => -3],
                ['description' => 'D', 'amount' => 1, 'account_code' => '6190'],
            ],
        ], $this->client);

        $this->assertSame(0.85, $out['items'][0]['confidence']);
        $this->assertSame(0.4, $out['items'][1]['confidence']);
        $this->assertSame(0.0, $out['items'][2]['confidence']);
        $this->assertSame(0.5, $out['items'][3]['confidence']);
    }

    public function test_mutasi_bank_dinormalisasi_per_baris(): void
    {
        $out = $this->extractor->normalize([
            'doc_type' => 'bank_statement',
            'document_date' => '2026-10-01',
            'transactions' => [
                ['date' => '2026-10-02', 'description' => 'TRSF E-BANKING CR', 'amount' => '5.000.000', 'direction' => 'in', 'account_code' => '4101'],
                // Tanggal kosong → warisi tanggal dokumen.
                ['description' => 'BIAYA ADM', 'amount' => 15000, 'direction' => 'out', 'account_code' => '7102'],
                // Arah ngawur → dianggap keluar (lebih aman: butuh koreksi manusia).
                ['description' => 'Entah', 'amount' => 1000, 'direction' => 'kemana', 'account_code' => '6190'],
            ],
        ], $this->client);

        $this->assertCount(3, $out['transactions']);
        $this->assertSame(5000000.0, $out['transactions'][0]['amount']);
        $this->assertSame('in', $out['transactions'][0]['direction']);
        $this->assertSame('2026-10-01', $out['transactions'][1]['date']);
        $this->assertSame('out', $out['transactions'][2]['direction']);
    }

    public function test_flow_tak_dikenal_jatuh_ke_expense(): void
    {
        $out = $this->extractor->normalize(['flow' => 'ngaco', 'items' => []], $this->client);

        $this->assertSame('expense', $out['flow']);
    }

    public function test_nominal_negatif_dijadikan_positif(): void
    {
        // Sisi debit/kredit ditentukan oleh flow/direction, bukan tanda minus —
        // nominal negatif yang lolos bisa bikin jurnal "balance" tapi salah arah.
        $out = $this->extractor->normalize([
            'items' => [['description' => 'Refund', 'amount' => '(250.000)', 'account_code' => '6190']],
        ], $this->client);

        $this->assertSame(250000.0, $out['items'][0]['amount']);
    }

    public function test_warning_duplikat_digabung(): void
    {
        $out = $this->extractor->normalize([
            'warnings' => ['Tulisan buram', 'Tulisan buram', '  '],
            'items' => [['description' => 'X', 'amount' => 1000, 'account_code' => '6190']],
        ], $this->client);

        $this->assertSame(['Tulisan buram'], array_values(array_filter(
            $out['warnings'],
            fn ($w) => $w === 'Tulisan buram',
        )));
    }
}
