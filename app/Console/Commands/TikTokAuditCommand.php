<?php

namespace App\Console\Commands;

use App\Models\TiktokOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Rincian order TikTok per status untuk satu bulan — menjawab pertanyaan yang
 * pasti berulang: "kenapa angka dashboard beda dengan TikTok Seller Center?".
 *
 * Dashboard sengaja TIDAK menghitung order belum-bayar & batal (belum tentu jadi
 * uang), sedangkan GMV Seller Center punya definisinya sendiri. Perintah ini
 * menunjukkan persis berapa yang dikecualikan, jadi selisihnya bisa dijelaskan
 * angka per angka — bukan ditebak.
 */
class TikTokAuditCommand extends Command
{
    protected $signature = 'tiktok:audit {--month= : YYYY-MM, default bulan ini}';

    protected $description = 'Rincian order TikTok per status (untuk cocokkan dengan Seller Center)';

    public function handle(): int
    {
        try {
            $m = $this->option('month') ? Carbon::parse($this->option('month').'-01') : now();
        } catch (\Throwable $e) {
            $this->error('Format bulan salah. Contoh: --month=2026-07');

            return self::FAILURE;
        }

        $start = $m->copy()->startOfMonth();
        $end = $m->copy()->endOfMonth();

        $rows = TiktokOrder::whereBetween('order_created_at', [$start, $end])
            ->selectRaw('status, COUNT(*) as n, COALESCE(SUM(total_amount),0) as total')
            ->groupBy('status')->orderByDesc('total')->get();

        if ($rows->isEmpty()) {
            $this->warn('Tidak ada order pada '.$start->translatedFormat('F Y'));

            return self::SUCCESS;
        }

        $this->info('Order TikTok — '.$start->translatedFormat('F Y').' (berdasarkan tanggal order masuk)');
        $this->newLine();

        $bucket = function (string $status): string {
            if (in_array($status, TiktokOrder::DELIVERED_STATUSES, true)) {
                return 'TEREALISASI';
            }
            if (in_array($status, TiktokOrder::PIPELINE_STATUSES, true)) {
                return 'BERJALAN';
            }

            return 'TIDAK DIHITUNG';
        };

        $this->table(
            ['Status', 'Order', 'Nilai', 'Masuk hitungan?'],
            $rows->map(fn ($r) => [
                $r->status ?? '(kosong)',
                number_format($r->n, 0, ',', '.'),
                'Rp '.number_format($r->total, 0, ',', '.'),
                $bucket((string) $r->status),
            ])->all(),
        );

        $sum = fn (string $b) => $rows->filter(fn ($r) => $bucket((string) $r->status) === $b)->sum('total');
        $cnt = fn (string $b) => $rows->filter(fn ($r) => $bucket((string) $r->status) === $b)->sum('n');

        $dashboard = $sum('TEREALISASI') + $sum('BERJALAN');
        $excluded = $sum('TIDAK DIHITUNG');

        $this->newLine();
        $this->line('Terealisasi      : Rp '.number_format($sum('TEREALISASI'), 0, ',', '.').'  ('.$cnt('TEREALISASI').' order)');
        $this->line('Masih berjalan   : Rp '.number_format($sum('BERJALAN'), 0, ',', '.').'  ('.$cnt('BERJALAN').' order)');
        $this->info('= Estimasi dashboard: Rp '.number_format($dashboard, 0, ',', '.'));
        $this->newLine();
        $this->warn('TIDAK dihitung   : Rp '.number_format($excluded, 0, ',', '.').'  ('.$cnt('TIDAK DIHITUNG').' order — belum bayar/batal)');
        $this->line('Total semua order: Rp '.number_format($rows->sum('total'), 0, ',', '.').'  ('.$rows->sum('n').' order)');
        $this->newLine();
        $this->line('Bandingkan "Total semua order" dengan GMV di Seller Center. Kalau GMV mendekati');
        $this->line('angka itu (bukan estimasi dashboard), berarti selisih = order belum bayar/batal.');

        return self::SUCCESS;
    }
}
