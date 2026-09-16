<?php

namespace App\Console\Commands;

use App\Models\TiktokAffiliateConnection;
use App\Services\TikTokAffiliateService;
use App\Services\TikTokClient;
use Illuminate\Console\Command;

/**
 * Lihat / daftarkan langganan webhook Customer Service TikTok (NEW_MESSAGE, event 14)
 * lewat Events API — tanpa mengandalkan UI Partner Center yang sering membingungkan.
 *
 *   php artisan ecom-chat:webhook            → tampilkan langganan yang aktif sekarang
 *   php artisan ecom-chat:webhook --register → daftarkan NEW_MESSAGE → URL webhook SKINKU
 *
 * Webhook chat memakai app affiliate ("Seller Analitik") — app yang punya scope CS.
 */
class EcomChatWebhookCommand extends Command
{
    protected $signature = 'ecom-chat:webhook {--register : Daftarkan/aktifkan langganan NEW_MESSAGE ke URL webhook SKINKU}';

    protected $description = 'Lihat / daftarkan langganan webhook NEW_MESSAGE TikTok (Customer Service) via API.';

    public function handle(TikTokAffiliateService $affiliate): int
    {
        $conn = TiktokAffiliateConnection::latest('id')->first();
        if (! $conn || ! $conn->shop_cipher) {
            $this->error('Belum terhubung ke TikTok affiliate (Seller Analitik) atau shop_cipher kosong.');

            return self::FAILURE;
        }

        $client = new TikTokClient('tiktok_affiliate');
        $access = $affiliate->freshToken($conn);
        $address = route('webhooks.tiktok.chat');

        if ($this->option('register')) {
            $this->line("Mendaftarkan NEW_MESSAGE → {$address} ...");
            try {
                $client->updateWebhook($access, $conn->shop_cipher, 'NEW_MESSAGE', $address);
                $this->info('OK — permintaan pendaftaran diterima TikTok.');
            } catch (\Throwable $e) {
                $this->error('GAGAL daftar: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        try {
            $data = $client->getWebhooks($access, $conn->shop_cipher);
        } catch (\Throwable $e) {
            $this->error('GAGAL ambil daftar webhook: '.$e->getMessage());

            return self::FAILURE;
        }

        $hooks = $data['webhooks'] ?? [];
        if ($hooks === []) {
            $this->warn('Belum ada langganan webhook APA PUN untuk toko ini.');
            $this->warn('Jalankan: php artisan ecom-chat:webhook --register');
            $this->line('Respons mentah: '.json_encode($data));
        } else {
            $this->info('Langganan webhook aktif:');
            foreach ($hooks as $h) {
                $this->line('  - '.($h['event_type'] ?? '?').'  →  '.($h['address'] ?? '?'));
            }
        }

        $this->newLine();
        $this->line('URL webhook SKINKU (harus sama dgn di atas utk NEW_MESSAGE): '.$address);

        return self::SUCCESS;
    }
}
