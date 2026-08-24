<?php

namespace App\Console\Commands;

use App\Services\ShopeeClient;
use Illuminate\Console\Command;

/**
 * Uji koneksi & tanda tangan ke API publik Shopee TANPA perlu connect toko.
 * Memanggil endpoint publik get_shops_by_partner (cukup partner_id + sign).
 * Kalau Shopee menerima tanda tangan → kredensial, base URL, dan HMAC kita benar.
 *
 * Pakai untuk memverifikasi Test creds (sandbox) sebelum Go-Live:
 *   SHOPEE_PARTNER_ID / SHOPEE_PARTNER_KEY (dari console) + SHOPEE_API_BASE sandbox.
 */
class ShopeePingCommand extends Command
{
    protected $signature = 'shopee:ping {--insecure : Lewati verifikasi SSL (untuk lokal dgn proxy/AV yang intersepsi TLS)}';

    protected $description = 'Uji koneksi & tanda tangan ke API publik Shopee (tanpa connect toko).';

    public function handle(ShopeeClient $shopee): int
    {
        if (! $shopee->configured()) {
            $this->error('SHOPEE_PARTNER_ID / SHOPEE_PARTNER_KEY belum diisi di .env.');

            return self::FAILURE;
        }

        if ($this->option('insecure')) {
            config(['services.shopee.insecure' => true]);
        }

        $this->line('Base URL   : '.config('services.shopee.api_base'));
        $this->line('Partner ID : '.config('services.shopee.partner_id'));
        $this->line('Memanggil  : /api/v2/public/get_shops_by_partner ...');

        try {
            $res = $shopee->getShopsByPartner();
        } catch (\Throwable $e) {
            $this->error('GAGAL: '.$e->getMessage());
            $this->warn('  - "wrong sign"        -> bug tanda tangan (harus diperbaiki).');
            $this->warn('  - "partner not found" -> partner_id / base URL salah (cek sandbox vs live).');
            $this->warn('  - timeout / DNS       -> base URL atau koneksi internet.');

            return self::FAILURE;
        }

        $shops = $res['authed_shop_list'] ?? [];
        $this->info('OK - Sign DITERIMA Shopee. Koneksi, kredensial, dan tanda tangan benar.');
        $this->line('Toko terotorisasi: '.count($shops).(
            count($shops) ? ' -> '.json_encode($shops) : ' (kosong - wajar sebelum ada toko yang connect).'
        ));

        return self::SUCCESS;
    }
}
