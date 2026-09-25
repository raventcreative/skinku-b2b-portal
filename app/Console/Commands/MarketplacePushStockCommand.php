<?php

namespace App\Console\Commands;

use App\Services\MarketplaceMasterService;
use Illuminate\Console\Command;

/**
 * Dorong stok+harga marketplace (diff push): hanya listing yang "kotor"
 * (stok/harga efektif berubah sejak terakhir dikirim) yang benar-benar
 * dikirim ke channel. Dijadwalkan tiap 5 menit lewat routes/console.php.
 */
class MarketplacePushStockCommand extends Command
{
    protected $signature = 'marketplace:push-stock';

    protected $description = 'Dorong stok marketplace (diff) ke TikTok & Shopee';

    public function handle(MarketplaceMasterService $svc): int
    {
        $r = $svc->pushDirty();
        $this->info("Push stok+harga: {$r['pushed']} terkirim · {$r['skipped']} dilewati · {$r['failed']} gagal.");

        return self::SUCCESS;
    }
}
