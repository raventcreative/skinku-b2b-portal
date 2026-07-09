<?php

namespace App\Http\Controllers;

use App\Exceptions\AccountingException;
use App\Models\AccAccount;
use App\Models\AccBranch;
use App\Models\AccJournal;
use App\Models\AccTemplate;
use App\Services\AccountingService;
use App\Services\AuditService;
use App\Services\FinancialReportService;
use App\Services\LedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountingController extends Controller
{
    public function __construct(
        private FinancialReportService $reports,
        private LedgerService $ledger,
        private AccountingService $accounting,
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

    /* ---------------- Jurnal Umum (input manual) ---------------- */

    public function journals(Request $request)
    {
        $period = $request->query('period');
        $journals = AccJournal::query()
            ->with('branch')
            ->withSum('lines as total', 'debit')
            ->when($period, fn ($q) => $q->where('period', $period))
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate(25)->withQueryString();

        return view('accounting.journals', [
            'journals' => $journals,
            'period' => $period ?: ($this->periods()[0]),
            'periods' => $this->periods(),
            'tab' => 'journals',
        ]);
    }

    public function journalCreate()
    {
        $templates = AccTemplate::active()->with('lines')->orderBy('name')->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'lines' => $t->lines->map(fn ($l) => ['account_id' => $l->account_id, 'side' => $l->side])->values(),
            ]);

        return view('accounting.journal_form', [
            'accounts' => AccAccount::active()->orderBy('code')->get(['id', 'code', 'name', 'normal_balance']),
            'branch' => AccBranch::active()->orderBy('id')->first(),
            'templates' => $templates,
        ]);
    }

    public function journalStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:acc_branches,id'],
            'date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'in:general,sales,purchase,cash_in,cash_out,inventory,adjustment'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['nullable', 'integer', 'exists:acc_accounts,id'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.memo' => ['nullable', 'string', 'max:150'],
        ]);

        $lines = collect($data['lines'])
            ->filter(fn ($l) => ! empty($l['account_id']) && ((float) ($l['debit'] ?? 0) > 0 || (float) ($l['credit'] ?? 0) > 0))
            ->map(fn ($l) => [
                'account_id' => (int) $l['account_id'],
                'debit' => (float) ($l['debit'] ?? 0),
                'credit' => (float) ($l['credit'] ?? 0),
                'memo' => $l['memo'] ?? null,
            ])->values()->all();

        try {
            $journal = $this->accounting->record([
                'branch_id' => $data['branch_id'],
                'date' => $data['date'],
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
                'type' => $data['type'] ?? 'general',
            ], $lines);
        } catch (AccountingException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        AuditService::log(action: 'create_journal', targetType: 'acc_journal', targetId: $journal->id, after: ['reference' => $journal->reference, 'period' => $journal->period]);

        return redirect()->route('accounting.journals')->with('status', 'Jurnal tercatat & balance.');
    }

    public function journalVoid(AccJournal $journal): RedirectResponse
    {
        $this->accounting->void($journal);
        AuditService::log(action: 'void_journal', targetType: 'acc_journal', targetId: $journal->id, after: ['reference' => $journal->reference]);

        return back()->with('status', 'Jurnal di-void (tidak lagi dihitung ke saldo).');
    }

    /* ---------------- Impor Mutasi Bank ---------------- */

    public function importForm()
    {
        return view('accounting.import', [
            'bankAccounts' => AccAccount::active()->cashLike()->orderBy('code')->get(['id', 'code', 'name']),
            'accounts' => AccAccount::active()->orderBy('code')->get(['id', 'code', 'name']),
            'branch' => AccBranch::active()->orderBy('id')->first(),
        ]);
    }

    /**
     * Terima baris mutasi yang sudah dipetakan + di-assign COA di browser, lalu
     * buat 1 jurnal per baris terhadap akun bank yang dipilih.
     *   - uang keluar (bank berkurang) → D akun-lawan / K bank
     *   - uang masuk  (bank bertambah) → D bank / K akun-lawan
     */
    public function importStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:acc_branches,id'],
            'bank_account_id' => ['required', 'integer', 'exists:acc_accounts,id'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.date' => ['nullable', 'date'],
            'rows.*.description' => ['nullable', 'string', 'max:255'],
            'rows.*.amount' => ['nullable', 'numeric', 'min:0'],
            'rows.*.direction' => ['nullable', 'in:masuk,keluar'],
            'rows.*.account_id' => ['nullable', 'integer', 'exists:acc_accounts,id'],
            'rows.*.ignore' => ['nullable'],
        ]);

        $bank = (int) $data['bank_account_id'];

        $result = DB::transaction(function () use ($data, $bank) {
            $imported = 0;
            $skipped = 0;

            foreach ($data['rows'] as $r) {
                $amount = (float) ($r['amount'] ?? 0);
                if (! empty($r['ignore']) || empty($r['account_id']) || empty($r['date']) || empty($r['direction']) || $amount <= 0) {
                    $skipped++;

                    continue;
                }

                $acc = (int) $r['account_id'];
                $lines = $r['direction'] === 'keluar'
                    ? [['account_id' => $acc, 'debit' => $amount], ['account_id' => $bank, 'credit' => $amount]]
                    : [['account_id' => $bank, 'debit' => $amount], ['account_id' => $acc, 'credit' => $amount]];

                $this->accounting->record([
                    'branch_id' => $data['branch_id'],
                    'date' => $r['date'],
                    'reference' => mb_substr(trim($r['description'] ?? 'Mutasi bank'), 0, 150),
                    'description' => $r['description'] ?? null,
                    'type' => $r['direction'] === 'keluar' ? 'cash_out' : 'cash_in',
                    'source_type' => 'bank_import',
                ], $lines);
                $imported++;
            }

            return compact('imported', 'skipped');
        });

        AuditService::log(action: 'import_bank_mutation', targetType: 'acc_journal', after: $result);

        return redirect()->route('accounting.journals')
            ->with('status', "{$result['imported']} transaksi diimpor dari mutasi bank.".($result['skipped'] ? " ({$result['skipped']} baris dilewati)" : ''));
    }
}
