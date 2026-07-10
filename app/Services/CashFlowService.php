<?php

namespace App\Services;

use App\Models\AccAccount;
use App\Models\AccJournal;
use App\Models\AccJournalLine;
use Illuminate\Support\Facades\DB;

/**
 * Laporan Arus Kas — METODE LANGSUNG.
 *
 * Untuk tiap jurnal (posted) yang menyentuh akun kas/bank, sisi LAWAN-nya
 * (baris non-kas) menentukan kategori arus: Operasi / Investasi / Pendanaan.
 * Dampak kas sebuah baris lawan = (credit − debit): + = kas masuk, − = kas keluar
 * (karena jurnal balance, jumlahnya = perubahan kas dari transaksi itu).
 *
 * Jurnal Saldo Awal (source_type=opening_balance) TIDAK dihitung sebagai arus —
 * itu masuk ke "kas awal periode".
 */
class CashFlowService
{
    /** @return array<int> */
    private function cashAccountIds(): array
    {
        return AccAccount::whereIn('subtype', ['cash', 'bank'])->pluck('id')->all();
    }

    public function directCashFlow(string $period, ?int $branch = null): array
    {
        $cashIds = $this->cashAccountIds();

        // Jurnal periode ini (posted, BUKAN saldo awal) yang menyentuh kas.
        $journalIds = AccJournal::query()
            ->where('status', AccJournal::STATUS_POSTED)
            ->where('period', $period)
            ->where(fn ($q) => $q->whereNull('source_type')->orWhere('source_type', '!=', 'opening_balance'))
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->whereHas('lines', fn ($q) => $q->whereIn('account_id', $cashIds))
            ->pluck('id');

        // Baris NON-kas dari jurnal2 itu, dikelompokkan per akun: Σ(credit − debit).
        $rows = AccJournalLine::query()
            ->whereIn('journal_id', $journalIds)
            ->whereNotIn('account_id', $cashIds)
            ->select('account_id', DB::raw('SUM(credit - debit) as net'))
            ->groupBy('account_id')
            ->get();

        $accounts = AccAccount::whereIn('id', $rows->pluck('account_id'))->get()->keyBy('id');

        $sections = ['operating' => [], 'investing' => [], 'financing' => []];
        foreach ($rows as $r) {
            $acc = $accounts[$r->account_id];
            $amount = (float) $r->net;
            if (abs($amount) < 0.005) {
                continue;
            }
            $sections[$this->category($acc)][] = [
                'code' => $acc->code, 'name' => $acc->name, 'amount' => $amount,
            ];
        }

        $totals = [];
        foreach ($sections as $key => $list) {
            usort($list, fn ($a, $b) => $b['amount'] <=> $a['amount']);
            $sections[$key] = $list;
            $totals[$key] = array_sum(array_column($list, 'amount'));
        }

        $net = $totals['operating'] + $totals['investing'] + $totals['financing'];

        // Kas awal = saldo kas dari SEMUA jurnal periode sebelumnya + jurnal saldo awal
        // (di mana pun periodenya). Kas akhir = kas awal + arus bersih.
        $kasAwal = $this->cashBefore($cashIds, $period, $branch);
        $kasAkhir = $kasAwal + $net;
        $actualAkhir = $this->cashAsOf($cashIds, $period, $branch);

        return [
            'period' => $period,
            'sections' => $sections,
            'totals' => $totals,
            'net' => $net,
            'kas_awal' => $kasAwal,
            'kas_akhir' => $kasAkhir,
            'reconciled' => abs($kasAkhir - $actualAkhir) < 0.05,
        ];
    }

    /** Klasifikasi akun lawan → kategori arus kas. */
    private function category(AccAccount $acc): string
    {
        if ($acc->type === 'equity') {
            return 'financing'; // modal, prive, ikhtisar
        }
        if ($acc->type === 'liability' && $acc->subtype === 'long_term') {
            return 'financing'; // hutang bank
        }
        if (in_array($acc->subtype, ['fixed_asset', 'contra_asset'], true)) {
            return 'investing'; // peralatan/perlengkapan usaha + akum. penyusutan
        }
        if ($acc->code === '1305') {
            return 'investing'; // DP Akuisisi Brand
        }

        return 'operating';
    }

    /** Saldo kas SEBELUM periode (periode < X) + semua jurnal saldo awal. */
    private function cashBefore(array $cashIds, string $period, ?int $branch): float
    {
        return (float) AccJournalLine::whereIn('account_id', $cashIds)
            ->whereHas('journal', function ($q) use ($period, $branch) {
                $q->where('status', AccJournal::STATUS_POSTED)
                    ->where(fn ($w) => $w->where('period', '<', $period)->orWhere('source_type', 'opening_balance'))
                    ->when($branch, fn ($qq) => $qq->where('branch_id', $branch));
            })
            ->sum(DB::raw('debit - credit'));
    }

    /** Saldo kas sampai akhir periode (periode <= X), semua sumber. */
    private function cashAsOf(array $cashIds, string $period, ?int $branch): float
    {
        return (float) AccJournalLine::whereIn('account_id', $cashIds)
            ->whereHas('journal', function ($q) use ($period, $branch) {
                $q->where('status', AccJournal::STATUS_POSTED)
                    ->where('period', '<=', $period)
                    ->when($branch, fn ($qq) => $qq->where('branch_id', $branch));
            })
            ->sum(DB::raw('debit - credit'));
    }
}
