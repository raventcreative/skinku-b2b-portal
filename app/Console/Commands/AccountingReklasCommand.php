<?php

namespace App\Console\Commands;

use App\Models\AccAccount;
use App\Models\AccJournal;
use App\Models\AccJournalLine;
use App\Services\AccountingService;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Pindahkan (reklasifikasi) saldo akun A ke akun B lewat JURNAL PENYESUAIAN per bulan
 * per cabang — jurnal lama tidak diubah, jejak audit tetap utuh. Contoh kasus: akun
 * manual "6014 Beban E-commerce (Tiktok)" digabung ke 6005 "Beban Biaya E-commerce".
 *
 * Default = pratinjau saja; tambah --jalankan untuk benar-benar membuat jurnal.
 * Aman diulang: setelah reklas, saldo A per bulan = 0 sehingga tak ada jurnal baru.
 */
class AccountingReklasCommand extends Command
{
    protected $signature = 'akuntansi:reklas {dari : kode akun asal} {ke : kode akun tujuan}
        {--jalankan : buat jurnal (tanpa ini = pratinjau)} {--nonaktifkan : nonaktifkan akun asal setelah saldonya nol}';

    protected $description = 'Pindahkan saldo akun asal ke akun tujuan lewat jurnal penyesuaian per bulan';

    public function handle(AccountingService $accounting): int
    {
        $from = AccAccount::where('code', $this->argument('dari'))->first();
        $to = AccAccount::where('code', $this->argument('ke'))->first();
        if (! $from || ! $to || $from->is($to)) {
            $this->error('Kode akun tidak ditemukan / sama.');

            return self::FAILURE;
        }
        if ($from->type !== $to->type) {
            $this->error("Tipe akun beda ({$from->type} vs {$to->type}) — reklas dibatalkan.");

            return self::FAILURE;
        }

        // Saldo bersih akun asal per bulan per cabang (debit − kredit), jurnal posted saja.
        $rows = AccJournalLine::query()
            ->join('acc_journals', 'acc_journals.id', '=', 'acc_journal_lines.journal_id')
            ->where('acc_journals.status', AccJournal::STATUS_POSTED)
            ->where('acc_journal_lines.account_id', $from->id)
            ->selectRaw('acc_journals.period, acc_journal_lines.branch_id, SUM(acc_journal_lines.debit) - SUM(acc_journal_lines.credit) as net')
            ->groupBy('acc_journals.period', 'acc_journal_lines.branch_id')
            ->orderBy('acc_journals.period')
            ->get()
            ->filter(fn ($r) => abs((float) $r->net) >= 0.005)
            ->values();

        $this->info("Reklas {$from->code} · {$from->name}  →  {$to->code} · {$to->name}");
        if ($rows->isEmpty()) {
            $this->line('Saldo akun asal sudah nol di semua bulan — tidak ada yang dipindah.');

            return $this->deactivate($from);
        }
        $this->table(['Periode', 'Cabang', 'Nilai dipindah'], $rows->map(fn ($r) => [
            $r->period, $r->branch_id, 'Rp '.number_format((float) $r->net, 2, ',', '.'),
        ])->all());
        $this->line('Total: Rp '.number_format((float) $rows->sum('net'), 2, ',', '.'));

        if (! $this->option('jalankan')) {
            $this->warn('Pratinjau saja. Jalankan ulang dengan --jalankan untuk membuat jurnal penyesuaian.');

            return self::SUCCESS;
        }

        foreach ($rows as $r) {
            $net = round((float) $r->net, 2);
            $end = Carbon::parse($r->period.'-01')->endOfMonth();
            $date = $end->isFuture() ? Carbon::today() : $end;
            // Saldo debit di asal → debit tujuan, kredit asal (dan sebaliknya bila saldonya kredit).
            $journal = $accounting->record(
                ['branch_id' => $r->branch_id, 'date' => $date->toDateString(), 'type' => 'adjustment',
                    'reference' => 'RKLS-'.$from->code.'-'.$to->code.'-'.$r->period,
                    'description' => "Reklasifikasi {$from->code} {$from->name} ke {$to->code} {$to->name}"],
                [
                    ['account_id' => $to->id, 'debit' => $net > 0 ? $net : 0, 'credit' => $net < 0 ? -$net : 0],
                    ['account_id' => $from->id, 'debit' => $net < 0 ? -$net : 0, 'credit' => $net > 0 ? $net : 0],
                ],
            );
            AuditService::log(action: 'reklas_akun', targetType: 'acc_journal', targetId: $journal->id,
                after: ['dari' => $from->code, 'ke' => $to->code, 'periode' => $r->period, 'nilai' => $net]);
            $this->line("✓ {$r->period}: jurnal #{$journal->id} dibuat");
        }

        return $this->deactivate($from);
    }

    private function deactivate(AccAccount $from): int
    {
        if ($this->option('nonaktifkan') && $this->option('jalankan') && $from->is_active) {
            $from->update(['is_active' => false]);
            AuditService::log(action: 'update_account', targetType: 'acc_account', targetId: $from->id,
                after: ['code' => $from->code, 'is_active' => false]);
            $this->info("Akun {$from->code} dinonaktifkan (tak muncul lagi di pilihan akun).");
        }

        return self::SUCCESS;
    }
}
