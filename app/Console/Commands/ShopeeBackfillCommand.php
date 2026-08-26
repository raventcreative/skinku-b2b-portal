<?php

namespace App\Console\Commands;

use App\Models\ShopeeOrder;
use App\Services\ShopeeSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Tarik ULANG seluruh order Shopee pada rentang tanggal.
 *
 * Kenapa perlu: sync rutin (shopee:sync) cuma melihat ~14 hari bergulir (batas
 * Shopee maks 15 hari/panggil), jadi tak pernah menutup lubang histori. Toko yang
 * sudah lama jalan bakal punya omzet setahun di Shopee tapi cuma 2 minggu di sistem.
 * Command ini iterasi window 14 hari dari --from ke --to biar histori lengkap.
 *
 * Aman diulang: penyimpanan idempoten (updateOrCreate) & status potong stok yang
 * sudah ada tak ter-reset. Store-only: TIDAK auto-potong stok (histori lama sudah
 * tercakup stok opname; deduct manual bila perlu).
 */
class ShopeeBackfillCommand extends Command
{
    protected $signature = 'shopee:backfill
        {--from= : Tanggal mulai (YYYY-MM-DD), default awal tahun ini}
        {--to= : Tanggal akhir inklusif (YYYY-MM-DD), default hari ini}';

    protected $description = 'Tarik ulang SEMUA order Shopee pada rentang tanggal (menutup lubang histori; Shopee batasi 15 hari/panggil → di-iterasi otomatis)';

    public function handle(ShopeeSyncService $sync): int
    {
        $conn = $sync->connection();
        if (! $conn) {
            $this->error('Belum terhubung ke Shopee.');

            return self::FAILURE;
        }

        try {
            $from = $this->option('from') ? Carbon::parse($this->option('from'))->startOfDay() : now()->startOfYear();
            $to = $this->option('to') ? Carbon::parse($this->option('to'))->endOfDay() : now()->endOfDay();
        } catch (\Throwable $e) {
            $this->error('Tanggal tidak valid. Format: YYYY-MM-DD');

            return self::FAILURE;
        }

        if ($from->gte($to)) {
            $this->error('--from harus lebih awal dari --to.');

            return self::FAILURE;
        }

        $this->info("Menarik order Shopee {$from->format('d M Y')} s/d {$to->format('d M Y')} (per 14 hari)…");

        $before = ShopeeOrder::whereBetween('order_created_at', [$from, $to])->count();

        try {
            $r = $sync->backfillOrders($conn, $from, $to);
        } catch (\Throwable $e) {
            $this->error('Gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $after = ShopeeOrder::whereBetween('order_created_at', [$from, $to])->count();

        $this->info("Ditarik dari Shopee  : {$r['pulled']} order");
        $this->info("Tersimpan/diperbarui : {$r['stored']}");
        $this->info("Order di rentang ini : {$before} → {$after} (+".($after - $before).')');
        $this->newLine();
        $this->line('Cocokkan dengan jumlah "Pesanan" di Shopee Seller Centre untuk rentang yang sama.');
        $this->line('Kalau masih kurang jauh, cek storage/logs/laravel-*.log.');

        return self::SUCCESS;
    }
}
