<?php

namespace App\Console\Commands;

use App\Services\TikTokClient;
use App\Services\TikTokSyncService;
use Illuminate\Console\Command;

/**
 * Diagnostik: panggil endpoint TikTok Shop API APA PUN (bertanda tangan) lalu
 * cetak respons asli TikTok. Dipakai buat cek ketersediaan/scope endpoint yang
 * belum kita integrasikan — mis. affiliate/creator discovery — tanpa nulis
 * integrasi dulu. Read-only kalau dipakai untuk GET / endpoint *search*.
 *
 * Sinyal penting dari respons:
 *   - "✓ SUKSES"            → endpoint kebuka & scope OK (layak diintegrasikan).
 *   - error code izin/scope → endpoint ADA tapi app belum punya izinnya
 *                             (aktifkan izin di Partner Center + re-authorize).
 *   - error path/sign       → endpoint tak tersedia utk region/app-mu.
 */
class TikTokProbeCommand extends Command
{
    protected $signature = 'tiktok:probe {path? : Path API, mis. /affiliate_creator/202405/marketplace_creators/search}
        {--method=GET : GET atau POST}
        {--body= : JSON body untuk POST, mis. \'{"page_size":10}\'}';

    protected $description = 'Diagnostik: panggil endpoint TikTok Shop API apa pun & cetak responsnya (cek scope/ketersediaan, mis. affiliate).';

    public function handle(TikTokSyncService $sync, TikTokClient $client): int
    {
        $conn = $sync->connection();
        if (! $conn || ! $conn->shop_cipher) {
            $this->error('Belum terhubung ke TikTok Shop — hubungkan dulu di menu Integrasi.');

            return self::FAILURE;
        }

        $token = $sync->freshToken($conn);

        // Baseline: buktikan token + signing jalan lewat endpoint yang PASTI kita
        // punya. Kalau ini gagal, kegagalan probe di bawah bukan soal scope.
        try {
            $shops = $client->request('GET', '/authorization/202309/shops', $token);
            $this->info('✓ Baseline OK — token & signing jalan (shops: '.count($shops['shops'] ?? []).').');
        } catch (\Throwable $e) {
            $this->error('Baseline GAGAL (token/signing bermasalah, bukan scope): '.$e->getMessage());

            return self::FAILURE;
        }

        $path = $this->argument('path');
        if (! $path) {
            $this->newLine();
            $this->line('Beri path buat probe. Contoh kandidat affiliate/creator:');
            $this->line('  php artisan tiktok:probe /affiliate_creator/202405/marketplace_creators/search --method=POST --body=\'{"page_size":10}\'');
            $this->line('  php artisan tiktok:probe /affiliate_seller/202405/orders/search --method=POST --body=\'{"page_size":10}\'');

            return self::SUCCESS;
        }

        $method = strtoupper((string) $this->option('method'));
        $body = $this->option('body') ? json_decode((string) $this->option('body'), true) : null;

        $this->newLine();
        $this->line("→ {$method} {$path}");
        try {
            $data = $client->request($method, $path, $token, $conn->shop_cipher, [], $body);
            $this->info('✓ SUKSES — endpoint kebuka & scope OK. Keys: '.implode(', ', array_keys($data)));
            $this->line(substr((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 0, 1500));
        } catch (\Throwable $e) {
            $this->warn('✗ '.$e->getMessage());
            $this->line('   → code izin/scope: endpoint ADA, app belum punya izin Affiliate (aktifkan di Partner Center + re-authorize).');
            $this->line('   → code path/sign : endpoint tak tersedia untuk region/app-mu.');
        }

        return self::SUCCESS;
    }
}
