<?php

namespace App\Http\Controllers;

use App\Exceptions\AccountingException;
use App\Models\AccAccount;
use App\Models\AccBranch;
use App\Models\AccJournal;
use App\Models\AccJournalLine;
use App\Models\AccTemplate;
use App\Services\AccountingService;
use App\Services\AuditService;
use App\Services\FinancialReportService;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
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

    /* ---------------- Impor Jurnal dari Excel ---------------- */

    public function excelImportForm()
    {
        return view('accounting.excel_import', [
            'accounts' => AccAccount::active()->orderBy('code')->get(['id', 'code', 'name', 'legacy_code', 'type', 'subtype']),
            'branch' => AccBranch::active()->orderBy('id')->first(),
        ]);
    }

    /** Sidik jari jurnal (dari tanggal + baris) untuk cegah impor dobel. */
    private function journalHash(int $branchId, string $date, string $reference, array $lines): string
    {
        $sig = collect($lines)
            ->map(fn ($l) => (int) $l['account_id'].':'.number_format((float) ($l['debit'] ?? 0), 2, '.', '').':'.number_format((float) ($l['credit'] ?? 0), 2, '.', ''))
            ->sort()->implode('|');

        return sha1($branchId.'|'.$date.'|'.trim($reference).'|'.$sig);
    }

    /**
     * Terima array JURNAL hasil parsing sheet Excel di browser (tiap jurnal sudah
     * balance debit=kredit dengan account_id app). Buat jurnal + dedup + tandai
     * source 'excel_import'.
     */
    public function excelImportStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:acc_branches,id'],
            'source_label' => ['nullable', 'string', 'max:100'],
            'journals' => ['required', 'array', 'min:1'],
            'journals.*.date' => ['required', 'date'],
            'journals.*.reference' => ['nullable', 'string', 'max:150'],
            'journals.*.description' => ['nullable', 'string', 'max:255'],
            'journals.*.type' => ['nullable', 'in:general,sales,purchase,cash_in,cash_out,inventory,adjustment'],
            'journals.*.lines' => ['required', 'array', 'min:2'],
            'journals.*.lines.*.account_id' => ['required', 'integer', 'exists:acc_accounts,id'],
            'journals.*.lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'journals.*.lines.*.credit' => ['nullable', 'numeric', 'min:0'],
        ]);

        $branch = (int) $data['branch_id'];
        $imported = 0;
        $duplicate = 0;
        $error = 0;
        $seen = []; // hitung kemunculan sidik jari yang identik DALAM 1 batch impor

        foreach ($data['journals'] as $j) {
            $date = $j['date'];
            $reference = mb_substr(trim($j['reference'] ?? ($data['source_label'] ?? 'Impor Excel')), 0, 150);
            $lines = array_map(fn ($l) => [
                'account_id' => (int) $l['account_id'],
                'debit' => (float) ($l['debit'] ?? 0),
                'credit' => (float) ($l['credit'] ?? 0),
            ], $j['lines']);

            // Dua transaksi yang BENAR-BENAR identik (tgl+ket+baris sama, mis. 2 penjualan
            // sama di hari sama) itu sah — jangan dianggap dobel. Tambahkan nomor urut
            // kemunculan ke sidik jari; impor file yang sama menghasilkan urutan sama →
            // tetap idempoten, tapi baris kembar asli tidak saling menimpa.
            $base = $this->journalHash($branch, $date, $reference, $lines);
            $occurrence = $seen[$base] = ($seen[$base] ?? 0) + 1;
            $hash = sha1($base.'#'.$occurrence);
            if (AccJournal::where('import_hash', $hash)->where('status', '!=', AccJournal::STATUS_VOID)->exists()) {
                $duplicate++;

                continue;
            }

            try {
                $journal = $this->accounting->record([
                    'branch_id' => $branch,
                    'date' => $date,
                    'reference' => $reference,
                    'description' => $j['description'] ?? null,
                    'type' => $j['type'] ?? 'general',
                    'source_type' => 'excel_import',
                ], $lines);
                $journal->import_hash = $hash;
                $journal->save();
                $imported++;
            } catch (AccountingException) {
                $error++; // jurnal tidak balance → dilewati
            }
        }

        AuditService::log(action: 'import_excel_journal', targetType: 'acc_journal', after: compact('imported', 'duplicate', 'error'));

        $msg = "{$imported} jurnal diimpor dari Excel.";
        if ($duplicate) {
            $msg .= " {$duplicate} sudah pernah diimpor (dilewati).";
        }
        if ($error) {
            $msg .= " {$error} tidak balance (dilewati).";
        }

        session()->flash('status', $msg);

        return response()->json([
            'ok' => true,
            'imported' => $imported,
            'duplicate' => $duplicate,
            'error' => $error,
            'redirect' => route('accounting.journals'),
        ]);
    }

    /**
     * Hapus permanen SEMUA jurnal hasil impor Excel (opsional per-periode). Untuk
     * membersihkan hasil impor yang salah lalu impor ulang. Hanya menyentuh
     * source_type='excel_import' — jurnal manual & impor bank aman.
     */
    public function excelImportPurge(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'period' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $query = AccJournal::where('source_type', 'excel_import');
        if (! empty($data['period'])) {
            $query->where('period', $data['period']);
        }
        $ids = $query->pluck('id');
        AccJournalLine::whereIn('journal_id', $ids)->delete();
        $count = AccJournal::whereIn('id', $ids)->delete();

        AuditService::log(action: 'purge_excel_import', targetType: 'acc_journal', after: ['deleted' => $count, 'period' => $data['period'] ?? 'all']);

        $scope = ! empty($data['period']) ? " periode {$data['period']}" : '';

        return redirect()->route('accounting.journals')->with('status', "{$count} jurnal hasil impor Excel{$scope} dihapus. Silakan impor ulang.");
    }

    /** Hapus permanen jurnal + barisnya (untuk bersihkan data test). Irreversible. */
    public function journalDestroy(AccJournal $journal): RedirectResponse
    {
        $ref = $journal->reference;
        $journal->delete(); // baris ikut terhapus (FK cascadeOnDelete)
        AuditService::log(action: 'delete_journal', targetType: 'acc_journal', targetId: $journal->id, after: ['reference' => $ref]);

        return back()->with('status', "Jurnal \"{$ref}\" dihapus permanen.");
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

    /** Sidik jari baris mutasi — stabil lintas impor (pakai saldo bila ada agar dupe asli tetap unik). */
    private function importHash(int $bankId, string $date, float $amount, string $direction, ?string $saldo, ?string $description): string
    {
        $tail = ($saldo !== null && $saldo !== '') ? preg_replace('/\s+/', '', $saldo) : trim((string) $description);

        return sha1($bankId.'|'.$date.'|'.number_format($amount, 2, '.', '').'|'.$direction.'|'.$tail);
    }

    /** Cek baris mana yang SUDAH pernah diimpor (untuk ditandai di pratinjau). */
    public function importCheck(Request $request)
    {
        $data = $request->validate([
            'bank_account_id' => ['required', 'integer', 'exists:acc_accounts,id'],
            'rows' => ['required', 'array'],
        ]);
        $bank = (int) $data['bank_account_id'];

        $hashes = collect($data['rows'])->map(fn ($r) => $this->importHash(
            $bank, $r['date'] ?? '', (float) ($r['amount'] ?? 0), $r['direction'] ?? '', $r['saldo'] ?? null, $r['description'] ?? null
        ));

        $existing = array_flip(
            AccJournal::whereIn('import_hash', $hashes->all())
                ->where('status', '!=', AccJournal::STATUS_VOID)
                ->pluck('import_hash')->all()
        );

        return response()->json($hashes->map(fn ($h) => isset($existing[$h]))->all());
    }

    /**
     * Terima baris mutasi yang sudah dipetakan + di-assign COA di browser, lalu
     * buat 1 jurnal per baris terhadap akun bank yang dipilih. Baris yang sudah
     * pernah diimpor (sidik jari sama) DILEWATI — jadi impor ulang tidak dobel.
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
            'rows.*.saldo' => ['nullable', 'string', 'max:50'],
            'rows.*.account_id' => ['nullable', 'integer', 'exists:acc_accounts,id'],
            'rows.*.ignore' => ['nullable'],
        ]);

        $bank = (int) $data['bank_account_id'];

        $result = DB::transaction(function () use ($data, $bank) {
            $imported = 0;
            $duplicate = 0;
            $skipped = 0;

            foreach ($data['rows'] as $r) {
                $amount = (float) ($r['amount'] ?? 0);
                if (! empty($r['ignore']) || empty($r['account_id']) || empty($r['date']) || empty($r['direction']) || $amount <= 0) {
                    $skipped++;

                    continue;
                }

                $hash = $this->importHash($bank, $r['date'], $amount, $r['direction'], $r['saldo'] ?? null, $r['description'] ?? null);
                if (AccJournal::where('import_hash', $hash)->where('status', '!=', AccJournal::STATUS_VOID)->exists()) {
                    $duplicate++;

                    continue; // sudah pernah diimpor — jangan dobel
                }

                $acc = (int) $r['account_id'];
                $lines = $r['direction'] === 'keluar'
                    ? [['account_id' => $acc, 'debit' => $amount], ['account_id' => $bank, 'credit' => $amount]]
                    : [['account_id' => $bank, 'debit' => $amount], ['account_id' => $acc, 'credit' => $amount]];

                $journal = $this->accounting->record([
                    'branch_id' => $data['branch_id'],
                    'date' => $r['date'],
                    'reference' => mb_substr(trim($r['description'] ?? 'Mutasi bank'), 0, 150),
                    'description' => $r['description'] ?? null,
                    'type' => $r['direction'] === 'keluar' ? 'cash_out' : 'cash_in',
                    'source_type' => 'bank_import',
                ], $lines);
                $journal->import_hash = $hash;
                $journal->save();
                $imported++;
            }

            return compact('imported', 'duplicate', 'skipped');
        });

        AuditService::log(action: 'import_bank_mutation', targetType: 'acc_journal', after: $result);

        $msg = "{$result['imported']} transaksi diimpor.";
        if ($result['duplicate']) {
            $msg .= " {$result['duplicate']} sudah pernah diimpor (dilewati, tidak dobel).";
        }
        if ($result['skipped']) {
            $msg .= " {$result['skipped']} tanpa COA/diabaikan.";
        }

        return redirect()->route('accounting.journals')->with('status', $msg);
    }
}
