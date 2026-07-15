<?php

namespace App\Console\Commands;

use App\Services\TikTokSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Tarik data TikTok otomatis lewat cron (pengganti tombol manual).
 * Aman diulang: penyimpanan & pemotongan stok idempoten, plus dijaga
 * batas tanggal (deduct_from) supaya order pra-opname tak ikut kepotong.
 */
class TikTokSyncCommand extends Command
{
    protected $signature = 'tiktok:sync {--returns : Sekalian tarik retur} {--settlements : Sekalian tarik pencairan}';

    protected $description = 'Tarik order TikTok (+auto-potong stok bila aktif); opsional retur & pencairan';

    public function handle(TikTokSyncService $sync): int
    {
        $conn = $sync->connection();
        if (! $conn || ! $conn->shop_cipher) {
            $this->warn('Belum terhubung ke TikTok Shop — dilewati.');

            return self::SUCCESS; // bukan error: cron jangan berisik
        }

        $failed = false;

        try {
            $r = $sync->syncOrders($conn);
            $msg = "Order: {$r['count']} tersimpan.";
            if ($r['deducted']) {
                $d = $r['deducted'];
                $msg .= " Auto-potong: {$d['done']} dipotong, {$d['failed']} gagal, {$d['skipped']} dilewati.";
            }
            $this->info($msg);
            Log::info('[tiktok:sync] '.$msg);
        } catch (\Throwable $e) {
            $failed = true;
            $this->error('Gagal tarik order: '.$e->getMessage());
            Log::error('[tiktok:sync] order gagal: '.$e->getMessage());
        }

        if ($this->option('returns')) {
            try {
                $n = $sync->syncReturns($conn);
                $this->info("Retur: {$n} tersimpan.");
            } catch (\Throwable $e) {
                $failed = true;
                $this->error('Gagal tarik retur: '.$e->getMessage());
                Log::error('[tiktok:sync] retur gagal: '.$e->getMessage());
            }
        }

        if ($this->option('settlements')) {
            try {
                $r = $sync->syncSettlements($conn);
                $this->info("Pencairan: {$r['count']} tersimpan.");
            } catch (\Throwable $e) {
                $failed = true;
                $this->error('Gagal tarik pencairan: '.$e->getMessage());
                Log::error('[tiktok:sync] pencairan gagal: '.$e->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
