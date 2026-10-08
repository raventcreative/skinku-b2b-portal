<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Journal;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\JournalPoster;
use App\Services\Reporting\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReportService $reports;

    private JournalPoster $poster;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reports = app(ReportService::class);
        $this->poster = app(JournalPoster::class);
        $this->client = Client::create(['name' => 'Klien Uji', 'type' => Client::TYPE_EXTERNAL]);
        app(ChartOfAccounts::class)->seedFor($this->client);
    }

    private function akun(string $code): int
    {
        return $this->client->accounts()->where('code', $code)->value('id');
    }

    private function jurnal(string $date, array $lines, string $status = Journal::STATUS_POSTED): Journal
    {
        return $this->poster->record([
            'client_id' => $this->client->id, 'date' => $date,
        ], array_map(fn ($l) => [
            'account_id' => $this->akun($l[0]),
            'debit' => $l[1] ?? 0,
            'credit' => $l[2] ?? 0,
        ], $lines), $status);
    }

    /** Set pembukuan kecil tapi lengkap: modal, penjualan, HPP, beban, bunga. */
    private function sebulanPembukuan(): void
    {
        $this->jurnal('2026-10-01', [['1102', 100_000_000], ['3101', 0, 100_000_000]]);   // setor modal
        $this->jurnal('2026-10-05', [['1102', 50_000_000], ['4101', 0, 50_000_000]]);      // penjualan
        $this->jurnal('2026-10-06', [['5101', 20_000_000], ['1301', 0, 20_000_000]]);      // HPP
        $this->jurnal('2026-10-10', [['6102', 5_000_000], ['1102', 0, 5_000_000]]);        // iklan
        $this->jurnal('2026-10-11', [['6101', 8_000_000], ['1102', 0, 8_000_000]]);        // gaji
        $this->jurnal('2026-10-20', [['7101', 1_000_000], ['1102', 0, 1_000_000]]);        // bunga
        $this->jurnal('2026-10-25', [['4104', 2_000_000], ['1102', 0, 2_000_000]]);        // retur penjualan
    }

    public function test_neraca_saldo_selalu_balance_dan_menyembunyikan_akun_nol(): void
    {
        $this->sebulanPembukuan();

        $report = $this->reports->trialBalance($this->client->id, '2026-10');

        $this->assertTrue($report['balanced']);
        $this->assertSame($report['total_debit'], $report['total_credit']);
        // Akun yang tak dipakai tidak boleh muncul (COA 50 akun, dipakai 9).
        $this->assertLessThan(15, count($report['rows']));
        foreach ($report['rows'] as $row) {
            $this->assertTrue($row['debit'] > 0 || $row['credit'] > 0);
        }
    }

    public function test_laba_rugi_mengelompokkan_hpp_opex_dan_non_operasional(): void
    {
        $this->sebulanPembukuan();

        $pnl = $this->reports->incomeStatement($this->client->id, '2026-10');

        // Retur penjualan (kontra-pendapatan) otomatis mengurangi penjualan.
        $this->assertSame(48_000_000.0, $pnl['totals']['revenue']);
        $this->assertSame(20_000_000.0, $pnl['totals']['cogs']);
        $this->assertSame(13_000_000.0, $pnl['totals']['opex']);
        $this->assertSame(1_000_000.0, $pnl['totals']['other']);

        $this->assertSame(28_000_000.0, $pnl['gross_profit']);
        $this->assertSame(15_000_000.0, $pnl['operating_income']);
        $this->assertSame(14_000_000.0, $pnl['net_income']);
    }

    public function test_neraca_balance_dengan_laba_berjalan_sebagai_ekuitas(): void
    {
        $this->sebulanPembukuan();

        $bs = $this->reports->balanceSheet($this->client->id, '2026-10');

        $this->assertTrue($bs['balanced'], 'Aktiva harus sama dengan pasiva.');
        $this->assertSame(0.0, $bs['difference']);
        // Kas 100jt +50jt -5jt -8jt -1jt -2jt = 134jt; persediaan -20jt.
        $this->assertSame(114_000_000.0, $bs['total_active']);
        $this->assertSame(100_000_000.0, $bs['totals']['equity']);
        $this->assertSame(14_000_000.0, $bs['retained_earnings']);
    }

    public function test_jurnal_void_dan_draft_tidak_masuk_laporan(): void
    {
        $this->jurnal('2026-10-05', [['1102', 10_000_000], ['4101', 0, 10_000_000]]);
        $draft = $this->jurnal('2026-10-06', [['1102', 99_000_000], ['4101', 0, 99_000_000]], Journal::STATUS_DRAFT);
        $void = $this->jurnal('2026-10-07', [['1102', 77_000_000], ['4101', 0, 77_000_000]]);
        $this->poster->void($void);

        $pnl = $this->reports->incomeStatement($this->client->id, '2026-10');

        $this->assertSame(10_000_000.0, $pnl['totals']['revenue']);
        $this->assertSame(Journal::STATUS_DRAFT, $draft->fresh()->status);
    }

    public function test_neraca_akumulasi_lintas_periode_laba_rugi_tidak(): void
    {
        $this->jurnal('2026-09-10', [['1102', 10_000_000], ['4101', 0, 10_000_000]]);
        $this->jurnal('2026-10-10', [['1102', 5_000_000], ['4101', 0, 5_000_000]]);

        // Laba Rugi = mutasi bulan itu saja.
        $this->assertSame(5_000_000.0, $this->reports->incomeStatement($this->client->id, '2026-10')['totals']['revenue']);
        $this->assertSame(10_000_000.0, $this->reports->incomeStatement($this->client->id, '2026-09')['totals']['revenue']);

        // Neraca = posisi akumulasi sampai akhir periode.
        $this->assertSame(15_000_000.0, $this->reports->balanceSheet($this->client->id, '2026-10')['total_active']);
        $this->assertSame(10_000_000.0, $this->reports->balanceSheet($this->client->id, '2026-09')['total_active']);
    }

    public function test_laporan_satu_klien_tidak_tercampur_klien_lain(): void
    {
        $this->jurnal('2026-10-05', [['1102', 10_000_000], ['4101', 0, 10_000_000]]);

        $lain = Client::create(['name' => 'Klien Lain', 'type' => Client::TYPE_EXTERNAL]);
        app(ChartOfAccounts::class)->seedFor($lain);
        app(JournalPoster::class)->record([
            'client_id' => $lain->id, 'date' => '2026-10-05',
        ], [
            ['account_id' => $lain->accounts()->where('code', '1102')->value('id'), 'debit' => 999_000_000],
            ['account_id' => $lain->accounts()->where('code', '4101')->value('id'), 'credit' => 999_000_000],
        ]);

        $this->assertSame(10_000_000.0, $this->reports->incomeStatement($this->client->id, '2026-10')['totals']['revenue']);
        $this->assertSame(999_000_000.0, $this->reports->incomeStatement($lain->id, '2026-10')['totals']['revenue']);
    }

    public function test_snapshot_dashboard_meringkas_kas_dan_beban_terbesar(): void
    {
        $this->sebulanPembukuan();

        $snapshot = $this->reports->snapshot($this->client->id, '2026-10');

        $this->assertSame(48_000_000.0, $snapshot['revenue']);
        $this->assertSame(34_000_000.0, $snapshot['expense']);
        $this->assertSame(14_000_000.0, $snapshot['net_income']);
        $this->assertSame(134_000_000.0, $snapshot['cash']);
        $this->assertLessThanOrEqual(5, count($snapshot['top_expenses']));
        // Terbesar duluan: HPP 20jt.
        $this->assertSame('5101', $snapshot['top_expenses'][0]['account']->code);
    }

    public function test_periode_tanpa_jurnal_balik_laporan_kosong_bukan_error(): void
    {
        $report = $this->reports->trialBalance($this->client->id, '2020-01');

        $this->assertSame([], $report['rows']);
        $this->assertTrue($report['balanced']);
        $this->assertSame(0.0, $this->reports->incomeStatement($this->client->id, '2020-01')['net_income']);
    }
}
