<?php

namespace App\Console\Commands;

use App\Services\ShopeeClient;
use App\Services\ShopeeSyncService;
use Illuminate\Console\Command;

/**
 * Fase 0 chat Shopee: buktikan API Seller Chat reachable dengan app/token kita,
 * lalu dump bentuk asli respons get_conversation_list + get_message ke file —
 * jadi acuan pasti untuk parser & pengirim (bukan tebak-tebakan).
 *
 *   php artisan shopee:chat-check                 → percakapan pertama
 *   php artisan shopee:chat-check --conv=171...    → percakapan tertentu
 */
class ShopeeChatCheckCommand extends Command
{
    protected $signature = 'shopee:chat-check {--conv= : conversation_id utk get_message} {--dump= : path simpan JSON}';

    protected $description = 'Fase 0: cek API chat Shopee + dump bentuk respons (conversation list + message).';

    public function handle(ShopeeClient $client, ShopeeSyncService $sync): int
    {
        $conn = $sync->connection();
        if (! $conn) {
            $this->error('Belum ada koneksi Shopee (ShopeeConnection kosong).');

            return self::FAILURE;
        }

        $access = $sync->freshToken($conn);
        $shopId = (string) $conn->shop_id;

        $list = $client->getConversationList($access, $shopId);
        if (($list['error'] ?? '') !== '') {
            $this->error('get_conversation_list ERROR: '.json_encode($list, JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }

        $convs = $list['response']['conversations'] ?? [];
        $this->info(count($convs).' percakapan terbaca. Field 1 percakapan: '.implode(', ', array_keys($convs[0] ?? [])));

        $out = ['conversation_list' => $list];

        $convId = $this->option('conv') ?: ($convs[0]['conversation_id'] ?? null);
        if ($convId) {
            $msgs = $client->getMessages($access, $shopId, (string) $convId);
            $out['messages'] = $msgs;
            if (($msgs['error'] ?? '') !== '') {
                $this->warn('get_message ERROR: '.json_encode($msgs, JSON_UNESCAPED_UNICODE));
            } else {
                $rows = $msgs['response']['messages'] ?? [];
                $this->info("get_message conv {$convId}: ".count($rows).' pesan. Field 1 pesan: '.implode(', ', array_keys($rows[0] ?? [])));
            }
        } else {
            $this->warn('Belum ada percakapan untuk diambil pesannya.');
        }

        $path = $this->option('dump') ?: storage_path('logs/shopee-chat-check.json');
        file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->info('Respons mentah disimpan: '.$path);

        return self::SUCCESS;
    }
}
