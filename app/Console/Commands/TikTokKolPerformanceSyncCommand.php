<?php

namespace App\Console\Commands;

use App\Models\Kol;
use App\Models\TiktokAffiliateConnection;
use App\Services\TikTokAffiliateService;
use Illuminate\Console\Command;

/**
 * Tracker performa TikTok mingguan: tarik performa 30 hari (endpoint
 * marketplace_creators/{open_id}) untuk KOL yang SEDANG kerja sama — status
 * aktif, deal berjalan, affiliate, atau Tim Gapok — lalu simpan snapshot hari
 * ini (riwayat grafik Detail KOL). Kuota marketplace dipakai bareng sync
 * harian, jadi dibatasi limit + jeda dan berhenti sopan saat kena rate limit.
 */
class TikTokKolPerformanceSyncCommand extends Command
{
    protected $signature = 'tiktok:kol-performance-sync
        {--limit=30 : Maksimum KOL per jalan}
        {--sleep=10 : Jeda detik antar panggilan}
        {--stale-days=6 : Lewati KOL yang performanya sudah ditarik dalam N hari terakhir}
        {--kol= : Hanya satu KOL (id)}';

    protected $description = 'Tarik performa TikTok 30 hari (views, engagement, GPM, GMV Rupiah) untuk KOL aktif → snapshot tracker.';

    public function handle(TikTokAffiliateService $svc): int
    {
        $conn = TiktokAffiliateConnection::latest('id')->first();
        if (! $conn || ! $conn->shop_cipher) {
            $this->error('App affiliate belum terhubung (TikTok Affiliate API).');

            return self::FAILURE;
        }

        $kols = $this->targets();
        if ($kols->isEmpty()) {
            $this->info('Tidak ada KOL aktif yang perlu ditarik performanya.');

            return self::SUCCESS;
        }

        $saved = $errors = 0;
        $sleep = max(0, (int) $this->option('sleep'));
        foreach ($kols->values() as $i => $kol) {
            try {
                $svc->syncKolPerformance($conn, $kol);
                $saved++;
                $this->line("  ✓ @{$kol->tiktok_username}");
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), '36009002') || stripos($e->getMessage(), 'too many requests') !== false) {
                    $this->warn("Kuota TikTok habis setelah {$saved} KOL — berhenti; sisanya dilanjut jalan berikutnya.");
                    break;
                }
                $errors++;
                $this->warn("  – @{$kol->tiktok_username}: {$e->getMessage()}");
            }
            if ($sleep > 0 && $i < $kols->count() - 1) {
                sleep($sleep);
            }
        }

        $this->info("Selesai: {$saved} tersimpan · {$errors} gagal.");

        return self::SUCCESS;
    }

    /** KOL yang sedang kerja sama + punya open_id + belum ditarik dalam stale-days. */
    private function targets()
    {
        if ($id = $this->option('kol')) {
            return Kol::with('tiktokProfile')->whereKey($id)->get();
        }
        $stale = now()->subDays(max(0, (int) $this->option('stale-days')));

        return Kol::query()
            ->with('tiktokProfile')
            ->whereHas('tiktokProfile', fn ($q) => $q->whereNotNull('open_id')->where('open_id', '!=', '')
                ->where(fn ($w) => $w->whereNull('performance_synced_at')->orWhere('performance_synced_at', '<', $stale)))
            ->where(fn ($q) => $q->where('status', Kol::STATUS_AKTIF)
                ->orWhere('is_gapok', true)
                ->orWhereIn('role', ['affiliate', 'both'])
                ->orWhereHas('deals', fn ($d) => $d->where('status', 'berjalan')))
            ->orderByDesc('followers')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();
    }
}
