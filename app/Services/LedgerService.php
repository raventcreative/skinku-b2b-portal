<?php

namespace App\Services;

use App\Models\AccJournal;
use App\Models\AccJournalLine;
use Illuminate\Support\Collection;

/**
 * Aggregation atas jurnal POSTED: Neraca Saldo (trial balance) & Buku Besar
 * (general ledger). Semua hanya menghitung jurnal berstatus 'posted' (void &
 * draft diabaikan). Period filter pakai 'YYYY-MM' (urut leksikografis = kronologis).
 */
class LedgerService
{
    /**
     * Saldo bersih (debit − kredit) per akun atas jurnal POSTED, difilter periode
     * ($fromPeriod..$toPeriod inklusif, 'YYYY-MM') + cabang. Basis semua laporan.
     * Untuk Neraca Saldo/Neraca: $fromPeriod = null (kumulatif). Untuk Laba Rugi:
     * $fromPeriod = $toPeriod = periode.
     *
     * @return Collection<int, object> objek: id, code, name, type, subtype, normal_balance, net
     */
    public function accountNets(?string $fromPeriod, ?string $toPeriod, ?int $branchId = null)
    {
        return AccJournalLine::query()
            ->join('acc_journals', 'acc_journals.id', '=', 'acc_journal_lines.journal_id')
            ->join('acc_accounts', 'acc_accounts.id', '=', 'acc_journal_lines.account_id')
            ->where('acc_journals.status', AccJournal::STATUS_POSTED)
            ->when($branchId, fn ($q) => $q->where('acc_journal_lines.branch_id', $branchId))
            ->when($fromPeriod, fn ($q) => $q->where('acc_journals.period', '>=', $fromPeriod))
            ->when($toPeriod, fn ($q) => $q->where('acc_journals.period', '<=', $toPeriod))
            ->groupBy('acc_accounts.id', 'acc_accounts.code', 'acc_accounts.name', 'acc_accounts.type', 'acc_accounts.subtype', 'acc_accounts.normal_balance')
            ->orderBy('acc_accounts.code')
            ->selectRaw('acc_accounts.id, acc_accounts.code, acc_accounts.name, acc_accounts.type, acc_accounts.subtype, acc_accounts.normal_balance,
                         COALESCE(SUM(acc_journal_lines.debit),0) as d, COALESCE(SUM(acc_journal_lines.credit),0) as c')
            ->get()
            ->map(function ($r) {
                $r->net = round((float) $r->d - (float) $r->c, 2);

                return $r;
            });
    }

    /**
     * Neraca Saldo — saldo akhir tiap akun sampai (dan termasuk) $asOfPeriod.
     * Saldo bersih diletakkan di kolom debit/kredit sesuai tanda. Total debit ==
     * total kredit (karena Σ posted debit == Σ posted credit).
     *
     * @return array{rows: array<int, array<string, mixed>>, total_debit: float, total_credit: float, balanced: bool}
     */
    public function trialBalance(?string $asOfPeriod = null, ?int $branchId = null): array
    {
        $rows = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($this->accountNets(null, $asOfPeriod, $branchId) as $r) {
            if (abs($r->net) < 0.005) {
                continue; // akun yang saldonya nol (fully offset) tidak ditampilkan
            }
            $debit = $r->net > 0 ? $r->net : 0.0;
            $credit = $r->net < 0 ? -$r->net : 0.0;

            $rows[] = [
                'id' => (int) $r->id,
                'code' => $r->code,
                'name' => $r->name,
                'type' => $r->type,
                'normal_balance' => $r->normal_balance,
                'debit' => $debit,
                'credit' => $credit,
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
     * Buku Besar satu akun: saldo awal (sebelum $fromPeriod) + mutasi + saldo
     * berjalan. Running balance = kumulatif (debit − kredit).
     *
     * @return array{opening: float, entries: array<int, array<string, mixed>>, closing: float}
     */
    public function generalLedger(int $accountId, ?string $fromPeriod = null, ?string $toPeriod = null, ?int $branchId = null): array
    {
        $base = AccJournalLine::query()
            ->join('acc_journals', 'acc_journals.id', '=', 'acc_journal_lines.journal_id')
            ->where('acc_journal_lines.account_id', $accountId)
            ->where('acc_journals.status', AccJournal::STATUS_POSTED)
            ->when($branchId, fn ($q) => $q->where('acc_journal_lines.branch_id', $branchId));

        $opening = 0.0;
        if ($fromPeriod) {
            $o = (clone $base)->where('acc_journals.period', '<', $fromPeriod)
                ->selectRaw('COALESCE(SUM(acc_journal_lines.debit),0) as d, COALESCE(SUM(acc_journal_lines.credit),0) as c')
                ->first();
            $opening = round((float) $o->d - (float) $o->c, 2);
        }

        $lines = (clone $base)
            ->when($fromPeriod, fn ($q) => $q->where('acc_journals.period', '>=', $fromPeriod))
            ->when($toPeriod, fn ($q) => $q->where('acc_journals.period', '<=', $toPeriod))
            ->orderBy('acc_journals.date')->orderBy('acc_journals.id')->orderBy('acc_journal_lines.id')
            ->get([
                'acc_journals.date as date',
                'acc_journals.reference as reference',
                'acc_journals.description as description',
                'acc_journal_lines.debit as debit',
                'acc_journal_lines.credit as credit',
            ]);

        $running = $opening;
        $entries = [];
        foreach ($lines as $l) {
            $running = round($running + (float) $l->debit - (float) $l->credit, 2);
            $entries[] = [
                'date' => $l->date,
                'reference' => $l->reference,
                'description' => $l->description,
                'debit' => (float) $l->debit,
                'credit' => (float) $l->credit,
                'running' => $running,
            ];
        }

        return ['opening' => $opening, 'entries' => $entries, 'closing' => $running];
    }
}
