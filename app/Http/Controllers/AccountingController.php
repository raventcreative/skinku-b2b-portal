<?php

namespace App\Http\Controllers;

use App\Models\AccJournal;
use App\Services\FinancialReportService;
use App\Services\LedgerService;
use Illuminate\Http\Request;

class AccountingController extends Controller
{
    public function __construct(
        private FinancialReportService $reports,
        private LedgerService $ledger,
    ) {}

    /** Distinct periods that have posted journals, newest first (for the dropdown). */
    private function periods(): array
    {
        $periods = AccJournal::query()
            ->where('status', AccJournal::STATUS_POSTED)
            ->distinct()->orderByDesc('period')->pluck('period')->all();

        if (empty($periods)) {
            $periods = [now()->format('Y-m')];
        }

        return $periods;
    }

    private function resolvePeriod(Request $request): string
    {
        $periods = $this->periods();
        $period = $request->query('period');

        return in_array($period, $periods, true) ? $period : $periods[0];
    }

    public function incomeStatement(Request $request)
    {
        $period = $this->resolvePeriod($request);

        return view('accounting.income_statement', [
            'report' => $this->reports->incomeStatement($period),
            'period' => $period,
            'periods' => $this->periods(),
            'tab' => 'income-statement',
        ]);
    }

    public function balanceSheet(Request $request)
    {
        $period = $this->resolvePeriod($request);

        return view('accounting.balance_sheet', [
            'report' => $this->reports->balanceSheet($period),
            'period' => $period,
            'periods' => $this->periods(),
            'tab' => 'balance-sheet',
        ]);
    }

    public function trialBalance(Request $request)
    {
        $period = $this->resolvePeriod($request);

        return view('accounting.trial_balance', [
            'report' => $this->ledger->trialBalance($period),
            'period' => $period,
            'periods' => $this->periods(),
            'tab' => 'trial-balance',
        ]);
    }
}
