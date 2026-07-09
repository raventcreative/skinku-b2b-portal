<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Laporan Keuangan dari jurnal POSTED, memakai `type` + `subtype` akun untuk
 * mapping ke baris laporan. Klasifikasi (dari ChartOfAccountSeeder):
 *   - revenue/sales + revenue/shipping     → Penjualan
 *   - revenue/contra_revenue               → Retur & Potongan (pengurang penjualan)
 *   - revenue/other                        → Pendapatan Lain-lain (non-operasional)
 *   - expense/cogs                         → HPP
 *   - expense/contra_cogs                  → pengurang HPP (retur pembelian)
 *   - expense/operating                    → Beban Operasional
 *   - expense/non_operating + expense/tax  → Beban Non-operasional (bunga, pajak)
 */
class FinancialReportService
{
    public function __construct(private LedgerService $ledger) {}

    /** Nilai positif searah saldo normal akun (kredit → -net, debit → net). */
    private function natural(object $r): float
    {
        return $r->normal_balance === 'credit' ? -$r->net : $r->net;
    }

    private function sum(Collection $rows): float
    {
        return round($rows->sum(fn ($r) => $this->natural($r)), 2);
    }

    private function items(Collection $rows): array
    {
        return $rows->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'amount' => $this->natural($r)])
            ->filter(fn ($x) => abs($x['amount']) >= 0.005)
            ->values()->all();
    }

    /** Laba Rugi untuk satu periode (aktivitas DALAM periode itu). */
    public function incomeStatement(string $period, ?int $branchId = null): array
    {
        $nets = $this->ledger->accountNets($period, $period, $branchId);
        $pick = fn (string $type, array $subtypes) => $nets->filter(
            fn ($r) => $r->type === $type && in_array($r->subtype, $subtypes, true)
        );

        $sales = $pick('revenue', ['sales', 'shipping']);
        $contraRev = $pick('revenue', ['contra_revenue']);
        $otherIncome = $pick('revenue', ['other']);
        $cogs = $pick('expense', ['cogs']);
        $contraCogs = $pick('expense', ['contra_cogs']);
        $opex = $pick('expense', ['operating']);
        $nonOp = $pick('expense', ['non_operating', 'tax']);

        $penjualanBruto = $this->sum($sales);
        $returPotongan = $this->sum($contraRev);
        $penjualanBersih = round($penjualanBruto - $returPotongan, 2);
        $hpp = round($this->sum($cogs) - $this->sum($contraCogs), 2);
        $labaKotor = round($penjualanBersih - $hpp, 2);
        $bebanOps = $this->sum($opex);
        $operatingIncome = round($labaKotor - $bebanOps, 2);
        $pendapatanLain = $this->sum($otherIncome);
        $bebanNonOp = $this->sum($nonOp);
        $netIncome = round($operatingIncome + $pendapatanLain - $bebanNonOp, 2);

        return [
            'period' => $period,
            'penjualan_bruto' => $penjualanBruto,
            'retur_potongan' => $returPotongan,
            'penjualan_bersih' => $penjualanBersih,
            'hpp' => $hpp,
            'laba_kotor' => $labaKotor,
            'beban_operasional' => $bebanOps,
            'operating_income' => $operatingIncome,
            'pendapatan_lain' => $pendapatanLain,
            'beban_non_operasional' => $bebanNonOp,
            'net_income' => $netIncome,
            'lines' => [
                'penjualan' => $this->items($sales),
                'retur_potongan' => $this->items($contraRev),
                'hpp' => $this->items($cogs->merge($contraCogs)),
                'beban_operasional' => $this->items($opex),
                'pendapatan_lain' => $this->items($otherIncome),
                'beban_non_operasional' => $this->items($nonOp),
            ],
        ];
    }

    /** Neraca per akhir $asOfPeriod (saldo kumulatif). Aktiva == Pasiva by construction. */
    public function balanceSheet(string $asOfPeriod, ?int $branchId = null): array
    {
        $nets = $this->ledger->accountNets(null, $asOfPeriod, $branchId);

        $assets = $nets->filter(fn ($r) => $r->type === 'asset');
        $liabilities = $nets->filter(fn ($r) => $r->type === 'liability');
        $equity = $nets->filter(fn ($r) => $r->type === 'equity');
        $revenue = $nets->filter(fn ($r) => $r->type === 'revenue');
        $expense = $nets->filter(fn ($r) => $r->type === 'expense');

        $totalAktiva = round($assets->sum(fn ($r) => $r->net), 2); // contra-asset ikut sbg negatif
        $totalLiabilitas = round($liabilities->sum(fn ($r) => -$r->net), 2);
        $modal = round($equity->sum(fn ($r) => -$r->net), 2);
        // Laba berjalan (year-to-date s/d periode) = pendapatan − beban.
        $labaBerjalan = round($revenue->sum(fn ($r) => -$r->net) - $expense->sum(fn ($r) => $r->net), 2);
        $totalEkuitas = round($modal + $labaBerjalan, 2);
        $totalPasiva = round($totalLiabilitas + $totalEkuitas, 2);

        return [
            'as_of' => $asOfPeriod,
            'aktiva' => $this->bsItems($assets, false),
            'total_aktiva' => $totalAktiva,
            'liabilitas' => $this->bsItems($liabilities, true),
            'total_liabilitas' => $totalLiabilitas,
            'ekuitas' => $this->bsItems($equity, true),
            'modal' => $modal,
            'laba_berjalan' => $labaBerjalan,
            'total_ekuitas' => $totalEkuitas,
            'total_pasiva' => $totalPasiva,
            'balanced' => abs($totalAktiva - $totalPasiva) < 0.005,
        ];
    }

    /** Saldo neraca: aset ditampilkan signed (net); liabilitas/ekuitas dibalik jadi positif. */
    private function bsItems(Collection $rows, bool $credit): array
    {
        return $rows->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'amount' => $credit ? -$r->net : $r->net])
            ->filter(fn ($x) => abs($x['amount']) >= 0.005)
            ->values()->all();
    }
}
