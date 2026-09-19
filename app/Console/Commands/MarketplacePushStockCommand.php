<?php

namespace App\Console\Commands;

use App\Services\MarketplaceStockService;
use Illuminate\Console\Command;

/**
 * Dorong stok marketplace (diff push): hanya listing yang "kotor" (pool
 * berubah sejak terakhir dikirim) yang benar-benar dikirim ke channel.
 * Dijadwalkan tiap 5 menit lewat routes/console.php.
 */
class MarketplacePushStockCommand extends Command
{
    protected $signature = 'marketplace:push-stock';

    protected $description = 'Dorong stok marketplace (diff) ke TikTok & Shopee';

    public function handle(MarketplaceStockService $svc): int
    {
        $r = $svc->pushDirty();
        $this->info("Push stok: {$r['pushed']} terkirim · {$r['skipped']} dilewati · {$r['failed']} gagal.");

        return self::SUCCESS;
    }
}
