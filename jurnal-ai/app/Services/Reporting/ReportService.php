<?php

namespace App\Services\Reporting;

use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalLine;
use Illuminate\Support\Collection;

/**
 * Agregasi saldo & laporan keuangan per klien. Semua angka HANYA dari jurnal
 * berstatus `posted` — draft belum berpengaruh, void otomatis keluar hitungan.
 *
 * Dua jenis agregat yang dipakai laporan:
 *   movements()  — mutasi DALAM satu periode → dasar Laba Rugi & Neraca Saldo.
 *   cumulative() — akumulasi SAMPAI akhir periode → dasar Neraca (posisi aset,
 *                  liabilitas, ekuitas itu saldo berjalan, bukan mutasi bulanan).
 */
class ReportService
{
    /**
     * Mutasi debit/kredit per akun di satu periode (YYYY-MM).
     *
     * @return Collection<int,object{account_id:int,debit:float,credit:float}>
     */
    public function movements(int $clientId, string $period): Collection
    {
        return $this->aggregate($clientId, fn ($q) => $q->where('journals.period', $period));
    }

    /**
     * Akumulasi debit/kredit per akun sampai (termasuk) periode tertentu.
     *
     * @return Collection<int,object{account_id:int,debit:float,credit:float}>
     */
    public function cumulative(int $clientId, string $period): Collection
    {
        return $this->aggregate($clientId, fn ($q) => $q->where('journals.period', '<=', $period));
    }

    private function aggregate(int $clientId, callable $scope): Collection
    {
        return JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journals.client_id', $clientId)
            ->where('journals.status', Journal::STATUS_POSTED)
            ->tap($scope)
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, COALESCE(SUM(journal_lines.debit),0) debit, COALESCE(SUM(journal_lines.credit),0) credit')
            ->get()
            ->keyBy('account_id');
    }

    /**
     * Neraca Saldo: mutasi per akun di periode terpilih + saldo bersih.
     * Baris berangka nol disembunyikan — akun yang tidak dipakai hanya bikin bising.
     *
     * @return array{rows:array<int,array<string,mixed>>,total_debit:float,total_credit:float,balanced:bool}
     */
    public function trialBalance(int $clientId, string $period): array
    {
        $movements = $this->movements($clientId, $period);
        $accounts = Account::where('client_id', $clientId)->orderBy('code')->get();

        $rows = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($accounts as $account) {
            $debit = (float) ($movements[$account->id]->debit ?? 0);
            $credit = (float) ($movements[$account->id]->credit ?? 0);
            if (abs($debit) < 0.005 && abs($credit) < 0.005) {
                continue;
            }

            $net = round($debit - $credit, 2);
            $rows[] = [
                'account' => $account,
                'debit' => $debit,
                'credit' => $credit,
                // Saldo ditampilkan sesuai sisi normal akun: akun kredit-normal
                // bersaldo positif kalau kredit > debit.
                'balance' => $account->isDebitNormal() ? $net : -$net,
            ];
            $totalDebit += $debit;
            $totalCredit += $credit;
        }

        return [
            'rows' => $rows,
            'total_debit' => round($totalDebit, 2),
            'total_credit' => round($totalCredit, 2),
            'balanced' => abs($totalDebit - $totalCredit) < 0.005,
        ];
    }

    /**
     * Laba Rugi periode: pendapatan − HPP − beban operasional − non-operasional.
     * Akun 5xxx dihitung HPP, 7xxx non-operasional, sisa 6xxx beban operasional —
     * pengelompokan ikut prefix kode karena itu konvensi template COA-nya.
     *
     * @return array<string,mixed>
     */
    public function incomeStatement(int $clientId, string $period): array
    {
        $movements = $this->movements($clientId, $period);
        $accounts = Account::where('client_id', $clientId)->orderBy('code')->get();

        $groups = ['revenue' => [], 'cogs' => [], 'opex' => [], 'other' => []];
        $totals = ['revenue' => 0.0, 'cogs' => 0.0, 'opex' => 0.0, 'other' => 0.0];

        foreach ($accounts as $account) {
            if (! in_array($account->type, [Account::TYPE_REVENUE, Account::TYPE_EXPENSE], true)) {
                continue;
            }

            $debit = (float) ($movements[$account->id]->debit ?? 0);
            $credit = (float) ($movements[$account->id]->credit ?? 0);
            // Nilai disajikan positif di sisi normalnya: pendapatan = kredit−debit
            // (jadi Retur/Diskon Penjualan yang debit-normal otomatis mengurangi).
            $value = $account->type === Account::TYPE_REVENUE
                ? round($credit - $debit, 2)
                : round($debit - $credit, 2);

            if (abs($value) < 0.005) {
                continue;
            }

            $key = match (true) {
                $account->type === Account::TYPE_REVENUE => 'revenue',
                str_starts_with($account->code, '5') => 'cogs',
                str_starts_with($account->code, '7') => 'other',
                default => 'opex',
            };

            $groups[$key][] = ['account' => $account, 'value' => $value];
            $totals[$key] += $value;
        }

        $totals = array_map(fn ($v) => round($v, 2), $totals);
        $grossProfit = round($totals['revenue'] - $totals['cogs'], 2);
        $operatingIncome = round($grossProfit - $totals['opex'], 2);

        return [
            'period' => $period,
            'groups' => $groups,
            'totals' => $totals,
            'gross_profit' => $grossProfit,
            'operating_income' => $operatingIncome,
            'net_income' => round($operatingIncome - $totals['other'], 2),
        ];
    }

    /**
     * Neraca per akhir periode. Laba/rugi berjalan (akumulasi pendapatan −
     * beban s/d periode ini) disajikan sebagai bagian ekuitas — tanpa itu
     * neraca tidak akan pernah balance sebelum jurnal penutup dibuat.
     *
     * @return array<string,mixed>
     */
    public function balanceSheet(int $clientId, string $period): array
    {
        $cumulative = $this->cumulative($clientId, $period);
        $accounts = Account::where('client_id', $clientId)->orderBy('code')->get();

        $groups = ['asset' => [], 'liability' => [], 'equity' => []];
        $totals = ['asset' => 0.0, 'liability' => 0.0, 'equity' => 0.0];
        $retained = 0.0;

        foreach ($accounts as $account) {
            $debit = (float) ($cumulative[$account->id]->debit ?? 0);
            $credit = (float) ($cumulative[$account->id]->credit ?? 0);
            $net = round($debit - $credit, 2);

            // Pendapatan & beban tidak muncul di neraca — diringkas jadi laba berjalan.
            if (in_array($account->type, [Account::TYPE_REVENUE, Account::TYPE_EXPENSE], true)) {
                $retained += $account->type === Account::TYPE_REVENUE ? -$net : -$net;

                continue;
            }

            if (! isset($groups[$account->type])) {
                continue;
            }

            $value = $account->type === Account::TYPE_ASSET ? $net : -$net;
            if (abs($value) < 0.005) {
                continue;
            }

            $groups[$account->type][] = ['account' => $account, 'value' => $value];
            $totals[$account->type] += $value;
        }

        $retained = round($retained, 2);
        $totals = array_map(fn ($v) => round($v, 2), $totals);
        $totalEquity = round($totals['equity'] + $retained, 2);
        $totalPassive = round($totals['liability'] + $totalEquity, 2);

        return [
            'period' => $period,
            'groups' => $groups,
            'totals' => $totals,
            'retained_earnings' => $retained,
            'total_equity' => $totalEquity,
            'total_active' => $totals['asset'],
            'total_passive' => $totalPassive,
            'difference' => round($totals['asset'] - $totalPassive, 2),
            'balanced' => abs($totals['asset'] - $totalPassive) < 0.005,
        ];
    }

    /**
     * Ringkasan untuk dashboard klien: beban & pendapatan periode ini, saldo kas,
     * dan 5 pos beban terbesar.
     *
     * @return array<string,mixed>
     */
    public function snapshot(int $clientId, string $period): array
    {
        $pnl = $this->incomeStatement($clientId, $period);

        $expenses = collect([...$pnl['groups']['cogs'], ...$pnl['groups']['opex'], ...$pnl['groups']['other']])
            ->sortByDesc('value')->take(5)->values()->all();

        $cumulative = $this->cumulative($clientId, $period);
        $cash = Account::where('client_id', $clientId)->payment()->get()
            ->sum(fn (Account $a) => (float) ($cumulative[$a->id]->debit ?? 0) - (float) ($cumulative[$a->id]->credit ?? 0));

        return [
            'period' => $period,
            'revenue' => $pnl['totals']['revenue'],
            'expense' => round($pnl['totals']['cogs'] + $pnl['totals']['opex'] + $pnl['totals']['other'], 2),
            'net_income' => $pnl['net_income'],
            'cash' => round($cash, 2),
            'top_expenses' => $expenses,
        ];
    }
}
