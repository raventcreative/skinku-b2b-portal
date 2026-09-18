<?php

namespace App\Console\Commands;

use App\Services\EcomChatService;
use Illuminate\Console\Command;

/**
 * Tarik chat Customer Service TikTok ke inbox SKINKU — backfill + SINKRON STATUS
 * dua arah. Dipakai cron (tiap beberapa menit) supaya balasan yang dibuat langsung
 * di Seller Center ikut tercermin ("Terbalas") di SKINKU, bukan cuma pesan masuk
 * pembeli yang lewat webhook realtime.
 */
class EcomChatSyncCommand extends Command
{
    protected $signature = 'ecom-chat:sync {--max=100 : Maksimal percakapan} {--msg=10 : Pesan terakhir per percakapan}';

    protected $description = 'Sinkron chat TikTok (Customer Service) ke inbox SKINKU (backfill + status 2 arah).';

    public function handle(EcomChatService $chat): int
    {
        $ok = true;

        // Tiap channel dibungkus terpisah: gagal 1 channel tak menggagalkan yang lain.
        try {
            $r = $chat->importFromTikTok((int) $this->option('max'), (int) $this->option('msg'));
            $this->info("TikTok: {$r['conversations']} percakapan, {$r['messages']} pesan baru.");
        } catch (\Throwable $e) {
            $ok = false;
            $this->error('TikTok gagal: '.$e->getMessage());
        }

        try {
            $r = $chat->importFromShopee((int) $this->option('max'));
            $this->info("Shopee: {$r['conversations']} percakapan.");
        } catch (\Throwable $e) {
            $ok = false;
            $this->error('Shopee gagal: '.$e->getMessage());
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
