<?php

namespace App\Console\Commands;

use App\Models\TiktokAffiliateConnection;
use App\Services\TikTokAffiliateService;
use Illuminate\Console\Command;

/**
 * Tarik order affiliate TikTok (Affiliate Seller API) → pipeline Tim Gapok.
 * Default 30 hari terakhir (cakup bulan berjalan). Dijadwalkan di routes/console.php.
 */
class TikTokAffiliateSyncCommand extends Command
{
    protected $signature = 'tiktok:affiliate-sync {--days=30}';

    protected $description = 'Tarik order affiliate TikTok per kreator → pipeline Tim Gapok';

    public function handle(TikTokAffiliateService $svc): int
    {
        $conn = TiktokAffiliateConnection::latest('id')->first();
        if (! $conn || ! $conn->shop_cipher) {
            $this->warn('App affiliate belum terhubung — lewati.');

            return self::SUCCESS; // bukan error: wajar sebelum di-connect.
        }

        $days = max(1, (int) $this->option('days'));

        try {
            $r = $svc->syncOrders($conn, now()->subDays($days), now(), null);
            $this->info("Sync affiliate: {$r['imported']} baris ({$r['matched']} cocok, {$r['unmatched']} belum) dari {$r['pages']} halaman.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Gagal sync affiliate: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
