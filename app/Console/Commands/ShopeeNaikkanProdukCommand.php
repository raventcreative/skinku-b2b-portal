<?php

namespace App\Console\Commands;

use App\Services\ShopeeBoostService;
use Illuminate\Console\Command;

/** Satu putaran "Naikkan Produk" Shopee otomatis (dijadwalkan tiap 10 menit). Lihat ShopeeBoostService. */
class ShopeeNaikkanProdukCommand extends Command
{
    protected $signature = 'shopee:naikkan-produk';

    protected $description = 'Naikkan lagi produk Shopee pilihan (maks 5) yang masa naik 4 jamnya sudah habis.';

    public function handle(ShopeeBoostService $boost): int
    {
        $hasil = $boost->jalankan();
        $this->line(json_encode($hasil, JSON_UNESCAPED_UNICODE));

        return $hasil['status'] === 'error' ? self::FAILURE : self::SUCCESS;
    }
}
