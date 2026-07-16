<?php

namespace App\Console\Commands;

use App\Models\TiktokOrder;
use App\Services\TikTokSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Tarik ULANG seluruh order pada rentang tanggal tertentu.
 *
 * Kenapa perlu: sync rutin hanya melihat perubahan sejak sinkron terakhir, jadi
 * tak pernah menutup lubang riwayat. Dan batas halaman lama (10 × 50 = 500 order)
 * membuat toko bervolume tinggi kehilangan sebagian besar ordernya secara diam-diam.
 *
 * Aman diulang: penyimpanan idempoten (updateOrCreate) & status potong stok
 * yang sudah ada tidak ter-reset.
 */
class TikTokBackfillCommand extends Command
{
    protected $signature = 'tiktok:backfill
        {--from= : Tanggal mulai (YYYY-MM-DD), default awal bulan ini}
        {--to= : Tanggal akhir inklusif (YYYY-MM-DD), default hari ini}';

    protected $description = 'Tarik ulang SEMUA order TikTok pada rentang tanggal (menutup lubang riwayat)';

    public function handle(TikTokSyncService $sync): int
    {
        $conn = $sync->connection();
        if (! $conn || ! $conn->shop_cipher) {
            $this->error('Belum terhubung ke TikTok Shop.');

            return self::FAILURE;
        }

        try {
            $from = $this->option('from') ? Carbon::parse($this->option('from'))->startOfDay() : now()->startOfMonth();
            $to = $this->option('to') ? Carbon::parse($this->option('to'))->endOfDay() : now()->endOfDay();
        } catch (\Throwable $e) {
            $this->error('Tanggal tidak valid. Format: YYYY-MM-DD');

            return self::FAILURE;
        }

        if ($from->gte($to)) {
            $this->error('--from harus lebih awal dari --to.');

            return self::FAILURE;
        }

        $this->info("Menarik order {$from->format('d M Y')} s/d {$to->format('d M Y')}…");

        $before = TiktokOrder::whereBetween('order_created_at', [$from, $to])->count();

        try {
            $r = $sync->backfillOrders($conn, $from, $to);
        } catch (\Throwable $e) {
            $this->error('Gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $after = TiktokOrder::whereBetween('order_created_at', [$from, $to])->count();

        $this->info("Ditarik dari TikTok : {$r['pulled']} order");
        $this->info("Tersimpan/diperbarui: {$r['stored']}");
        $this->info("Order di rentang ini : {$before} → {$after} (+".($after - $before).')');
        $this->newLine();
        $this->line('Cocokkan angka terakhir dengan jumlah "Pesanan" di TikTok Seller Center');
        $this->line('untuk rentang yang sama. Kalau masih kurang jauh, cek storage/logs.');

        return self::SUCCESS;
    }
}
