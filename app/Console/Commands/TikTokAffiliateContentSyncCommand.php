<?php

namespace App\Console\Commands;

use App\Models\TiktokAffiliateConnection;
use App\Services\TikTokAffiliateService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Sync jumlah video & LIVE per kreator affiliate (Analytics API) → Tim Gapok.
 * Berat (page-through banyak) → dijadwalkan harian, bukan tiap 6 jam.
 * --month=YYYY-MM buat backfill bulan tertentu (default bulan berjalan).
 */
class TikTokAffiliateContentSyncCommand extends Command
{
    protected $signature = 'tiktok:affiliate-content-sync {--month=}';

    protected $description = 'Sync jumlah video & LIVE per kreator affiliate → Tim Gapok';

    public function handle(TikTokAffiliateService $svc): int
    {
        $conn = TiktokAffiliateConnection::latest('id')->first();
        if (! $conn || ! $conn->shop_cipher) {
            $this->warn('App affiliate belum terhubung — lewati.');

            return self::SUCCESS;
        }

        $opt = (string) $this->option('month');
        $month = preg_match('/^\d{4}-\d{2}$/', $opt)
            ? Carbon::createFromFormat('Y-m', $opt)->startOfMonth()
            : now()->startOfMonth();

        try {
            $r = $svc->syncContentStats($conn, $month);
            $this->info("Content sync {$month->format('Y-m')}: {$r['videos']} video, {$r['lives']} LIVE → {$r['creators']} kreator tersimpan.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Gagal content sync: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
