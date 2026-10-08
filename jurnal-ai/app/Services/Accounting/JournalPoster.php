<?php

namespace App\Services\Accounting;

use App\Exceptions\AccountingException;
use App\Models\Account;
use App\Models\Client;
use App\Models\Journal;
use App\Models\JournalLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya pintu pembuatan jurnal. Aturan yang dipaksa di sini (level
 * aplikasi, bukan cuma DB):
 *   - minimal 2 baris;
 *   - satu baris hanya debit ATAU kredit, tidak boleh dua-duanya / nol;
 *   - total debit == total kredit (toleransi < 0,005);
 *   - semua akun harus milik klien yang sama (isolasi buku antar klien);
 *   - `period` diturunkan dari tanggal, tidak pernah diisi manual.
 *
 * Void = tandai status 'void'. Semua saldo dihitung dari jurnal 'posted' saja,
 * jadi void otomatis tidak ikut terhitung — tanpa perlu jurnal balik.
 */
class JournalPoster
{
    /**
     * @param  array{client_id:int,date:string,reference?:?string,description?:?string,type?:string,document_id?:?int,fingerprint?:?string,created_by?:?int}  $header
     * @param  array<int,array{account_id:int|string,debit?:mixed,credit?:mixed,memo?:?string}>  $lines
     *
     * @throws AccountingException
     */
    public function record(array $header, array $lines, string $status = Journal::STATUS_POSTED): Journal
    {
        $clientId = (int) ($header['client_id'] ?? 0);
        if ($clientId <= 0) {
            throw new AccountingException('Jurnal wajib punya klien.');
        }
        if (blank($header['date'] ?? null)) {
            throw new AccountingException('Jurnal wajib punya tanggal.');
        }

        [$normalized, $totalDebit, $totalCredit] = $this->normalize($lines);

        if (count($normalized) < 2) {
            throw new AccountingException('Jurnal harus punya minimal 2 baris berisi nilai.');
        }
        if (abs(round($totalDebit, 2) - round($totalCredit, 2)) >= 0.005) {
            throw new AccountingException(sprintf(
                'Jurnal tidak balance: debit Rp%s ≠ kredit Rp%s (selisih Rp%s).',
                number_format($totalDebit, 2, ',', '.'),
                number_format($totalCredit, 2, ',', '.'),
                number_format(abs($totalDebit - $totalCredit), 2, ',', '.'),
            ));
        }

        $this->assertAccountsBelongTo($clientId, array_column($normalized, 'account_id'));

        $date = Carbon::parse($header['date']);

        return DB::transaction(function () use ($header, $clientId, $normalized, $status, $date) {
            $journal = Journal::create([
                'client_id' => $clientId,
                'document_id' => $header['document_id'] ?? null,
                'date' => $date->toDateString(),
                'period' => $date->format('Y-m'),
                'reference' => $header['reference'] ?? null,
                'description' => $header['description'] ?? null,
                'type' => $header['type'] ?? 'general',
                'status' => $status,
                'fingerprint' => $header['fingerprint'] ?? null,
                'created_by' => $header['created_by'] ?? null,
            ]);

            foreach ($normalized as $i => $n) {
                $journal->lines()->create($n + ['sort_order' => $i]);
            }

            return $journal->load('lines.account');
        });
    }

    /**
     * Bersihkan & validasi baris. Baris kosong (debit & kredit nol) dilewati
     * tanpa error — form selalu mengirim baris kosong di bawah.
     *
     * @return array{0:array<int,array{account_id:int,debit:float,credit:float,memo:?string}>,1:float,2:float}
     */
    private function normalize(array $lines): array
    {
        $out = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($lines as $i => $line) {
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);
            $no = $i + 1;

            if ($debit < 0 || $credit < 0) {
                throw new AccountingException("Baris #{$no}: debit/kredit tidak boleh negatif.");
            }
            if ($debit > 0 && $credit > 0) {
                throw new AccountingException("Baris #{$no}: satu baris tidak boleh debit dan kredit sekaligus.");
            }
            if ($debit === 0.0 && $credit === 0.0) {
                continue;
            }
            if (empty($line['account_id'])) {
                throw new AccountingException("Baris #{$no}: akun wajib dipilih.");
            }

            $out[] = [
                'account_id' => (int) $line['account_id'],
                'debit' => $debit,
                'credit' => $credit,
                'memo' => $line['memo'] ?? null,
            ];
            $totalDebit += $debit;
            $totalCredit += $credit;
        }

        return [$out, $totalDebit, $totalCredit];
    }

    /**
     * Pagar isolasi antar klien: akun dari COA klien lain tidak boleh nyelip ke
     * jurnal klien ini (kalau lolos, laporan dua klien jadi tercampur).
     */
    private function assertAccountsBelongTo(int $clientId, array $accountIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $accountIds)));
        $valid = Account::whereIn('id', $ids)->where('client_id', $clientId)->pluck('id')->all();
        $invalid = array_diff($ids, $valid);

        if ($invalid !== []) {
            throw new AccountingException(
                'Akun #'.implode(', #', $invalid).' bukan milik klien ini — jurnal ditolak.'
            );
        }
    }

    /** Draft → posted (validasi balance diulang, jangan percaya status lama). */
    public function post(Journal $journal): Journal
    {
        if ($journal->status === Journal::STATUS_POSTED) {
            return $journal;
        }
        if ($journal->status === Journal::STATUS_VOID) {
            throw new AccountingException('Jurnal yang sudah void tidak bisa diposting.');
        }

        $journal->load('lines');
        if (! $journal->isBalanced()) {
            throw new AccountingException('Jurnal tidak balance, tidak bisa diposting.');
        }

        $journal->update(['status' => Journal::STATUS_POSTED]);

        return $journal;
    }

    /** Void — berhenti dihitung di semua saldo & laporan. */
    public function void(Journal $journal): Journal
    {
        $journal->update(['status' => Journal::STATUS_VOID]);

        return $journal;
    }

    /** true kalau sidik jari ini sudah pernah diposting (anti-dobel). */
    public function alreadyPosted(int $clientId, string $fingerprint): bool
    {
        return Journal::where('client_id', $clientId)
            ->where('fingerprint', $fingerprint)
            ->where('status', '!=', Journal::STATUS_VOID)
            ->exists();
    }

    /**
     * Sidik jari transaksi — stabil lintas upload supaya struk/baris mutasi yang
     * sama tidak masuk dua kali walau dokumennya difoto ulang.
     */
    public static function fingerprint(int $clientId, string $date, float $amount, string $extra = ''): string
    {
        return hash('sha256', implode('|', [
            $clientId,
            $date,
            number_format($amount, 2, '.', ''),
            preg_replace('/\s+/', ' ', mb_strtolower(trim($extra))) ?? '',
        ]));
    }

    /** Mutasi bersih (debit - kredit) satu akun atas jurnal posted. Positif = sisi debit. */
    public function balanceOf(int $accountId, ?string $period = null): float
    {
        $row = JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $accountId)
            ->where('journals.status', Journal::STATUS_POSTED)
            ->when($period, fn ($q) => $q->where('journals.period', $period))
            ->selectRaw('COALESCE(SUM(journal_lines.debit),0) d, COALESCE(SUM(journal_lines.credit),0) c')
            ->first();

        return round((float) $row->d - (float) $row->c, 2);
    }

    /** Jumlah jurnal posted milik klien (dipakai dashboard & cek klien kosong). */
    public function postedCount(Client $client): int
    {
        return $client->journals()->posted()->count();
    }
}
