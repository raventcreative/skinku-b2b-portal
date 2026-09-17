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
        try {
            $res = $chat->importFromTikTok((int) $this->option('max'), (int) $this->option('msg'));
            $this->info("Sinkron chat selesai: {$res['conversations']} percakapan, {$res['messages']} pesan baru.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Gagal sinkron chat: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
