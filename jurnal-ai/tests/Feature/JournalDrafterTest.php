<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Document;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Intake\JournalDrafter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Usulan jurnal dari hasil baca AI. Tuntutan utama: apa pun yang dibaca model,
 * draft yang keluar HARUS balance — kalau tidak, user dihadapkan form yang
 * pasti ditolak server.
 */
class JournalDrafterTest extends TestCase
{
    use RefreshDatabase;

    private JournalDrafter $drafter;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->drafter = app(JournalDrafter::class);
        $this->client = Client::create(['name' => 'Klien Uji', 'type' => Client::TYPE_EXTERNAL]);
        app(ChartOfAccounts::class)->seedFor($this->client);
    }

    private function dokumen(array $extraction, string $kind = Document::KIND_RECEIPT): Document
    {
        return $this->client->documents()->create([
            'kind' => $kind,
            'status' => Document::STATUS_EXTRACTED,
            'extraction' => $extraction,
        ]);
    }

    /** @return array{0:float,1:float} total debit & kredit satu draft */
    private function totals(array $draft): array
    {
        return [
            round(array_sum(array_column($draft['lines'], 'debit')), 2),
            round(array_sum(array_column($draft['lines'], 'credit')), 2),
        ];
    }

    private function kode(array $draft, string $side): array
    {
        return array_values(array_map(
            fn ($l) => $l['account_code'],
            array_filter($draft['lines'], fn ($l) => $l[$side] > 0),
        ));
    }

    public function test_belanja_tunai_jadi_debit_beban_kredit_kas(): void
    {
        $drafts = $this->drafter->draft($this->dokumen([
            'flow' => 'expense',
            'document_date' => '2026-10-08',
            'payment_method' => 'cash',
            'vendor' => 'Toko Kemasan',
            'tax' => 0, 'discount' => 0, 'total' => 750000,
            'items' => [
                ['description' => 'Botol 500ml', 'amount' => 700000, 'account_code' => '5104', 'qty' => 200, 'unit' => 'pcs', 'confidence' => 0.9],
                ['description' => 'Label', 'amount' => 50000, 'account_code' => '5104', 'confidence' => 0.8],
            ],
        ]));

        $this->assertCount(1, $drafts);
        [$debit, $credit] = $this->totals($drafts[0]);
        $this->assertSame(750000.0, $debit);
        $this->assertSame($debit, $credit, 'Draft wajib balance.');
        $this->assertSame(['5104', '5104'], $this->kode($drafts[0], 'debit'));
        $this->assertSame(['1101'], $this->kode($drafts[0], 'credit'));
        $this->assertSame('2026-10-08', $drafts[0]['date']);
    }

    public function test_pajak_masuk_ppn_masukan_dan_tetap_balance(): void
    {
        $drafts = $this->drafter->draft($this->dokumen([
            'flow' => 'expense',
            'document_date' => '2026-10-08',
            'payment_method' => 'bank',
            'tax' => 110000, 'discount' => 0, 'total' => 1110000,
            'items' => [['description' => 'Jasa desain', 'amount' => 1000000, 'account_code' => '6109']],
        ], Document::KIND_INVOICE));

        [$debit, $credit] = $this->totals($drafts[0]);
        $this->assertSame($debit, $credit);
        $this->assertContains('1402', $this->kode($drafts[0], 'debit'), 'PPN wajib masuk akun PPN Masukan.');
        // Sisi bayar = total termasuk pajak.
        $this->assertSame(1110000.0, $debit);
        $this->assertSame(['1102'], $this->kode($drafts[0], 'credit'));
    }

    public function test_diskon_mengurangi_lewat_sisi_berlawanan_dan_tetap_balance(): void
    {
        $drafts = $this->drafter->draft($this->dokumen([
            'flow' => 'expense',
            'document_date' => '2026-10-08',
            'payment_method' => 'ewallet',
            'tax' => 0, 'discount' => 50000, 'total' => 950000,
            'items' => [['description' => 'Belanja', 'amount' => 1000000, 'account_code' => '6107']],
        ]));

        [$debit, $credit] = $this->totals($drafts[0]);
        $this->assertSame($debit, $credit);

        // Yang benar-benar keluar dari e-wallet = 950.000.
        $pay = array_values(array_filter($drafts[0]['lines'], fn ($l) => $l['account_code'] === '1103'));
        $this->assertSame(950000.0, $pay[0]['credit']);
    }

    public function test_belum_dibayar_jadi_hutang_usaha(): void
    {
        $drafts = $this->drafter->draft($this->dokumen([
            'flow' => 'expense',
            'document_date' => '2026-10-08',
            'payment_method' => 'credit',
            'tax' => 0, 'discount' => 0, 'total' => 2000000,
            'items' => [['description' => 'Maklon produksi', 'amount' => 2000000, 'account_code' => '5103']],
        ], Document::KIND_INVOICE));

        $this->assertSame(['2101'], $this->kode($drafts[0], 'credit'));
        $this->assertSame('purchase', $drafts[0]['type']);
    }

    public function test_pemasukan_dibalik_rincian_di_kredit(): void
    {
        $drafts = $this->drafter->draft($this->dokumen([
            'flow' => 'income',
            'document_date' => '2026-10-08',
            'payment_method' => 'bank',
            'tax' => 0, 'discount' => 0, 'total' => 3000000,
            'items' => [['description' => 'Penjualan Oktober', 'amount' => 3000000, 'account_code' => '4101']],
        ]));

        [$debit, $credit] = $this->totals($drafts[0]);
        $this->assertSame($debit, $credit);
        $this->assertSame(['4101'], $this->kode($drafts[0], 'credit'));
        $this->assertSame(['1102'], $this->kode($drafts[0], 'debit'));
        $this->assertSame('sale', $drafts[0]['type']);
    }

    public function test_penjualan_kredit_jadi_piutang(): void
    {
        $drafts = $this->drafter->draft($this->dokumen([
            'flow' => 'income',
            'document_date' => '2026-10-08',
            'payment_method' => 'credit',
            'tax' => 0, 'discount' => 0, 'total' => 1000000,
            'items' => [['description' => 'Invoice klien', 'amount' => 1000000, 'account_code' => '4102']],
        ], Document::KIND_INVOICE));

        $this->assertSame(['1201'], $this->kode($drafts[0], 'debit'));
    }

    public function test_mutasi_bank_jadi_satu_jurnal_per_baris_dengan_sidik_jari_berbeda(): void
    {
        $drafts = $this->drafter->draft($this->dokumen([
            'flow' => 'expense',
            'document_date' => '2026-10-01',
            'payment_method' => 'bank',
            'tax' => 0, 'discount' => 0,
            'items' => [],
            'transactions' => [
                ['date' => '2026-10-02', 'description' => 'Transfer masuk', 'amount' => 5000000, 'direction' => 'in', 'account_code' => '4101', 'confidence' => 0.9],
                ['date' => '2026-10-03', 'description' => 'Biaya admin', 'amount' => 15000, 'direction' => 'out', 'account_code' => '7102', 'confidence' => 0.95],
            ],
        ], Document::KIND_BANK));

        $this->assertCount(2, $drafts);

        // Uang masuk: D bank / K pendapatan.
        $this->assertSame(['1102'], $this->kode($drafts[0], 'debit'));
        $this->assertSame(['4101'], $this->kode($drafts[0], 'credit'));
        // Uang keluar: D beban / K bank.
        $this->assertSame(['7102'], $this->kode($drafts[1], 'debit'));
        $this->assertSame(['1102'], $this->kode($drafts[1], 'credit'));

        foreach ($drafts as $draft) {
            [$debit, $credit] = $this->totals($draft);
            $this->assertSame($debit, $credit);
            $this->assertSame('bank', $draft['type']);
        }

        $this->assertNotSame($drafts[0]['fingerprint'], $drafts[1]['fingerprint']);
    }

    public function test_mutasi_e_wallet_memakai_akun_e_wallet_sebagai_sisi_rekening(): void
    {
        $drafts = $this->drafter->draft($this->dokumen([
            'payment_method' => 'ewallet',
            'document_date' => '2026-10-01',
            'items' => [],
            'transactions' => [
                ['date' => '2026-10-02', 'description' => 'Bayar QRIS', 'amount' => 25000, 'direction' => 'out', 'account_code' => '6111'],
            ],
        ], Document::KIND_BANK));

        $this->assertSame(['1103'], $this->kode($drafts[0], 'credit'));
    }

    public function test_ekstraksi_kosong_tidak_menghasilkan_draft(): void
    {
        $this->assertSame([], $this->drafter->draft($this->dokumen([])));
        $this->assertSame([], $this->drafter->draft($this->dokumen([
            'flow' => 'expense', 'items' => [], 'transactions' => [],
        ])));
    }

    public function test_baris_dengan_akun_yang_sudah_dihapus_dilewati(): void
    {
        // Akun bisa dinonaktifkan setelah dokumen dibaca — draft tidak boleh
        // merujuk akun mati (jurnalnya bakal ditolak poster).
        $this->client->accounts()->where('code', '6102')->update(['is_active' => false]);

        $drafts = $this->drafter->draft($this->dokumen([
            'flow' => 'expense',
            'document_date' => '2026-10-08',
            'payment_method' => 'cash',
            'tax' => 0, 'discount' => 0, 'total' => 600000,
            'items' => [
                ['description' => 'Iklan (akun nonaktif)', 'amount' => 500000, 'account_code' => '6102'],
                ['description' => 'Ongkir', 'amount' => 100000, 'account_code' => '6106'],
            ],
        ]));

        $this->assertSame(['6106'], $this->kode($drafts[0], 'debit'));
        [$debit, $credit] = $this->totals($drafts[0]);
        $this->assertSame($debit, $credit, 'Sisa baris tetap harus balance.');
        $this->assertSame(100000.0, $debit);
    }
}
