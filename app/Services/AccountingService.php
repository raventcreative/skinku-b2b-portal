<?php

namespace App\Services;

use App\Exceptions\AccountingException;
use App\Models\AccJournal;
use App\Models\AccJournalLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Posting engine — the single doorway for creating accounting journals.
 *
 * Rules enforced here (aplikasi-level, bukan cuma DB):
 *   - tiap baris hanya boleh debit ATAU kredit (tidak dua-duanya, tidak nol).
 *   - minimal 2 baris.
 *   - total debit == total kredit (dibulatkan ke 2 desimal), kalau tidak → ditolak.
 *   - period diturunkan otomatis dari tanggal (YYYY-MM).
 *   - branch_id per baris default ikut header.
 *
 * Void = tandai status 'void'; saldo dihitung hanya dari jurnal 'posted', jadi
 * jurnal void otomatis tidak ikut (efeknya membalik saldo).
 */
class AccountingService
{
    /**
     * @param  array{branch_id:int, date:string, reference?:?string, description?:?string, type?:string, source_type?:?string, source_id?:?int}  $header
     * @param  array<int, array{account_id:int, debit?:float, credit?:float, memo?:?string, branch_id?:int}>  $lines
     *
     * @throws AccountingException
     */
    public function record(array $header, array $lines, string $status = AccJournal::STATUS_POSTED): AccJournal
    {
        if (empty($header['branch_id'])) {
            throw new AccountingException('Jurnal wajib punya cabang (branch_id).');
        }

        $normalized = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($lines as $i => $line) {
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);

            if ($debit < 0 || $credit < 0) {
                throw new AccountingException("Baris #{$i}: nilai debit/kredit tidak boleh negatif.");
            }
            if ($debit > 0 && $credit > 0) {
                throw new AccountingException("Baris #{$i}: satu baris tidak boleh debit dan kredit sekaligus.");
            }
            if ($debit === 0.0 && $credit === 0.0) {
                continue; // lewati baris kosong
            }
            if (empty($line['account_id'])) {
                throw new AccountingException("Baris #{$i}: akun (account_id) wajib diisi.");
            }

            $normalized[] = [
                'account_id' => (int) $line['account_id'],
                'branch_id' => (int) ($line['branch_id'] ?? $header['branch_id']),
                'debit' => $debit,
                'credit' => $credit,
                'memo' => $line['memo'] ?? null,
            ];
            $totalDebit += $debit;
            $totalCredit += $credit;
        }

        if (count($normalized) < 2) {
            throw new AccountingException('Jurnal harus punya minimal 2 baris.');
        }
        if (abs(round($totalDebit, 2) - round($totalCredit, 2)) >= 0.005) {
            throw new AccountingException(
                'Jurnal tidak balance: total debit Rp'.number_format($totalDebit, 2, ',', '.').
                ' ≠ total kredit Rp'.number_format($totalCredit, 2, ',', '.').'.'
            );
        }

        $date = Carbon::parse($header['date']);

        return DB::transaction(function () use ($header, $normalized, $status, $date) {
            $journal = AccJournal::create([
                'branch_id' => $header['branch_id'],
                'date' => $date->toDateString(),
                'period' => $date->format('Y-m'),
                'reference' => $header['reference'] ?? null,
                'description' => $header['description'] ?? null,
                'type' => $header['type'] ?? 'general',
                'status' => $status,
                'source_type' => $header['source_type'] ?? null,
                'source_id' => $header['source_id'] ?? null,
            ]);

            foreach ($normalized as $n) {
                $journal->lines()->create($n);
            }

            return $journal->load('lines');
        });
    }

    /** Promote a draft journal to posted (revalidates balance). */
    public function post(AccJournal $journal): AccJournal
    {
        if ($journal->status === AccJournal::STATUS_POSTED) {
            return $journal;
        }
        if ($journal->status === AccJournal::STATUS_VOID) {
            throw new AccountingException('Jurnal yang sudah void tidak bisa diposting.');
        }

        $journal->load('lines');
        if (! $journal->isBalanced()) {
            throw new AccountingException('Jurnal tidak balance, tidak bisa diposting.');
        }

        $journal->status = AccJournal::STATUS_POSTED;
        $journal->save();

        return $journal;
    }

    /** Void a journal — it stops counting toward balances (posted-only). */
    public function void(AccJournal $journal): AccJournal
    {
        $journal->status = AccJournal::STATUS_VOID;
        $journal->save();

        return $journal;
    }

    /**
     * Net movement (debit - credit) of an account over POSTED journals only.
     * Optional period filter 'YYYY-MM'. Positive = debit side.
     */
    public function balanceOf(int $accountId, ?string $period = null): float
    {
        $row = AccJournalLine::query()
            ->join('acc_journals', 'acc_journals.id', '=', 'acc_journal_lines.journal_id')
            ->where('acc_journal_lines.account_id', $accountId)
            ->where('acc_journals.status', AccJournal::STATUS_POSTED)
            ->when($period, fn ($q) => $q->where('acc_journals.period', $period))
            ->selectRaw('COALESCE(SUM(acc_journal_lines.debit),0) as d, COALESCE(SUM(acc_journal_lines.credit),0) as c')
            ->first();

        return round((float) $row->d - (float) $row->c, 2);
    }
}
