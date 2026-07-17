<?php

namespace App\Console\Commands;

use App\Services\TikTokSyncService;
use Illuminate\Console\Command;

/**
 * Isi keterangan pencairan TikTok (potongan apa) tanpa perlu diklik.
 *
 * Ini satu-satunya tarikan TikTok yang dulu MANUAL, dan bukan karena disengaja:
 * keterangan butuh satu panggilan API per statement, jadi tombolnya dibatasi 60
 * per klik supaya request web tak timeout. Cron tak punya batasan itu — tiap
 * jam ia mengunyah tumpukannya sampai habis, lalu diam sendiri.
 */
class TikTokDescribeCommand extends Command
{
    protected $signature = 'tiktok:describe {--limit=60 : Jumlah statement per jalan}';

    protected $description = 'Isi keterangan pencairan TikTok yang masih kosong (potongan apa)';

    public function handle(TikTokSyncService $sync): int
    {
        $conn = $sync->connection();

        if (! $conn || ! $conn->shop_cipher) {
            $this->warn('Belum terhubung ke TikTok Shop — dilewati.');

            return self::SUCCESS;
        }

        // Tak ada tunggakan → pulang tanpa menyentuh API sama sekali. Perintah ini
        // jalan tiap jam; memanggil API cuma untuk menemukan "tak ada kerjaan"
        // membuang kuota yang dibutuhkan sinkron order.
        if ($sync->sisaTanpaKeterangan() === 0) {
            $this->info('Semua pencairan sudah berketerangan — tak ada yang dikerjakan.');

            return self::SUCCESS;
        }

        $r = $sync->describeSettlements($conn, (int) $this->option('limit'));

        $this->info("Keterangan diisi: {$r['done']}, gagal: {$r['failed']}, sisa: {$r['remaining']}");

        // Gagal semua = ada yang rusak (token/scope/rate limit), bukan sekadar
        // sepi kerjaan. Keluar dengan status gagal supaya kelihatan di log cron.
        if ($r['done'] === 0 && $r['failed'] > 0) {
            $this->error('Semua percobaan gagal — periksa log, kemungkinan token/scope Finance atau rate limit.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
