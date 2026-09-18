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
    protected $signature = 'shopee:chat-check {--conv= : conversation_id utk get_message} {--scan= : pindai N percakapan, ambil 1 contoh tiap message_type} {--dir=latest : arah get_conversation_list (latest|older)} {--dump= : path simpan JSON}';

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

        $dir = $this->option('dir') === 'older' ? 'older' : 'latest';
        $list = $client->getConversationList($access, $shopId, $dir, 'all', 50);
        if (($list['error'] ?? '') !== '') {
            $this->error('get_conversation_list ERROR: '.json_encode($list, JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }

        $convs = $list['response']['conversations'] ?? [];
        $this->info(count($convs)." percakapan (dir={$dir}). Field: ".implode(', ', array_keys($convs[0] ?? [])));

        // Ringkas rentang tanggal + urutan (ts nanodetik → /1e9 detik).
        $first = $convs[0]['last_message_timestamp'] ?? 0;
        $last = end($convs)['last_message_timestamp'] ?? 0;
        $fmt = fn ($ns) => $ns ? date('Y-m-d H:i', (int) ((int) $ns / 1_000_000_000)) : '-';
        $this->info("Teratas: {$convs[0]['to_name']} @ ".$fmt($first).'  |  Terbawah: '.(end($convs)['to_name'] ?? '?').' @ '.$fmt($last));
        $pr = $list['response']['page_result'] ?? [];
        $this->info('page_result: '.json_encode($pr, JSON_UNESCAPED_UNICODE));

        $out = ['conversation_list' => $list];

        // Mode PINDAI: cari 1 contoh tiap message_type di N percakapan pertama.
        if ($scan = (int) $this->option('scan')) {
            $samples = [];
            $counts = [];
            foreach (array_slice($convs, 0, $scan) as $c) {
                $cid = (string) ($c['conversation_id'] ?? '');
                if ($cid === '') {
                    continue;
                }
                $rows = $client->getMessages($access, $shopId, $cid, 30)['response']['messages'] ?? [];
                foreach ($rows as $m) {
                    $t = (string) ($m['message_type'] ?? 'unknown');
                    $counts[$t] = ($counts[$t] ?? 0) + 1;
                    if (! isset($samples[$t])) {
                        $samples[$t] = $m;
                    }
                }
            }
            $out['type_counts'] = $counts;
            $out['type_samples'] = $samples;
            $this->info('Tipe pesan ditemukan: '.json_encode($counts, JSON_UNESCAPED_UNICODE));
        } else {
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
        }

        $path = $this->option('dump') ?: storage_path('logs/shopee-chat-check.json');
        file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->info('Respons mentah disimpan: '.$path);

        return self::SUCCESS;
    }
}
