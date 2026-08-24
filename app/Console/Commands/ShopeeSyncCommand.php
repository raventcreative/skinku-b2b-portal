<?php

namespace App\Console\Commands;

use App\Services\ShopeeSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ShopeeSyncCommand extends Command
{
    protected $signature = 'shopee:sync {--full : Abaikan filter waktu, sapu 14 hari terakhir} {--returns : Sekalian tarik retur} {--settlements : Sekalian tarik pencairan/escrow}';

    protected $description = 'Tarik order Shopee (+auto-potong stok bila aktif)';

    public function handle(ShopeeSyncService $sync): int
    {
        $conn = $sync->connection();
        if (! $conn) {
            $this->warn('Belum terhubung ke Shopee — dilewati.');

            return self::SUCCESS;
        }
        try {
            $r = $sync->syncOrders($conn, null, (bool) $this->option('full'));
            $msg = "Order: {$r['count']} tersimpan.";
            if ($r['deducted']) {
                $d = $r['deducted'];
                $msg .= " Auto-potong: {$d['done']} dipotong, {$d['failed']} gagal, {$d['skipped']} dilewati.";
            }
            $this->info($msg);
            Log::info('[shopee:sync] '.$msg);

            if ($this->option('returns')) {
                try {
                    $rn = $sync->syncReturns($conn);
                    $this->info("Retur: {$rn} tersimpan.");
                    Log::info("[shopee:sync] Retur: {$rn} tersimpan.");
                } catch (\Throwable $e) {
                    $this->error('Gagal tarik retur: '.$e->getMessage());
                    Log::error('[shopee:sync] retur gagal: '.$e->getMessage());

                    return self::FAILURE;
                }
            }

            if ($this->option('settlements')) {
                try {
                    $r = $sync->syncSettlements($conn);
                    $this->info("Pencairan: {$r['count']} tersimpan.");
                    Log::info("[shopee:sync] Pencairan: {$r['count']} tersimpan.");
                } catch (\Throwable $e) {
                    $this->error('Gagal tarik pencairan: '.$e->getMessage());
                    Log::error('[shopee:sync] pencairan gagal: '.$e->getMessage());

                    return self::FAILURE;
                }
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Gagal: '.$e->getMessage());
            Log::error('[shopee:sync] '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
