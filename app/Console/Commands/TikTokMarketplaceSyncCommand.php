<?php

namespace App\Console\Commands;

use App\Models\Kol;
use App\Models\TiktokAffiliateConnection;
use App\Services\TikTokAffiliateService;
use Illuminate\Console\Command;

/**
 * Sync MASSAL profil TikTok Creator Marketplace ke Database KOL: buat tiap KOL,
 * cari username-nya di marketplace, cocokkan PERSIS, lalu simpan follower + GMV
 * asli + demografi (via TikTokAffiliateService::applyCreatorToKol). Ber-throttle
 * (jeda antar-panggilan) & BERHENTI sopan saat kena rate limit — jalankan lagi
 * untuk melanjutkan (yang sudah dicek dilewati via kols.tiktok_checked_at).
 */
class TikTokMarketplaceSyncCommand extends Command
{
    protected $signature = 'tiktok:marketplace-sync
        {--limit=40 : Maksimum KOL diproses sekali jalan}
        {--sleep=3 : Jeda detik antar panggilan (jaga rate limit)}
        {--stale-days=30 : Cek ulang KOL yang terakhir dicek lebih lama dari ini}
        {--force : Abaikan penanda tiktok_checked_at, cek semua}';

    protected $description = 'Tarik profil TikTok Creator Marketplace (follower/GMV/demografi) untuk KOL secara massal.';

    public function handle(TikTokAffiliateService $svc): int
    {
        $conn = TiktokAffiliateConnection::latest('id')->first();
        if (! $conn || ! $conn->shop_cipher) {
            $this->error('App affiliate belum terhubung (TikTok Affiliate API).');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $sleep = max(0, (int) $this->option('sleep'));
        $staleDays = max(0, (int) $this->option('stale-days'));
        $force = (bool) $this->option('force');

        $kols = Kol::query()
            ->whereNotNull('tiktok_username')->where('tiktok_username', '!=', '')
            ->when(! $force, fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('tiktok_checked_at')
                ->orWhere('tiktok_checked_at', '<', now()->subDays($staleDays))))
            ->orderByDesc('followers')
            ->limit($limit)
            ->get();

        if ($kols->isEmpty()) {
            $this->info('Tidak ada KOL yang perlu dicek. Semua sudah tersinkron (pakai --force untuk paksa).');

            return self::SUCCESS;
        }

        $this->info("Proses {$kols->count()} KOL (limit {$limit}, jeda {$sleep}s)…");
        $saved = $skipped = $errors = 0;

        foreach ($kols as $i => $kol) {
            $uname = mb_strtolower(trim((string) $kol->tiktok_username));

            try {
                $creators = $svc->searchCreators($conn, (string) $kol->tiktok_username, 12);
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), '36009002') || stripos($e->getMessage(), 'too many requests') !== false) {
                    $this->warn("Rate limit TikTok kena di @{$uname}. BERHENTI — jalankan lagi nanti untuk lanjut (yang sudah tersimpan dilewati).");
                    break;
                }
                $this->warn("@{$uname}: gagal — {$e->getMessage()}");
                $errors++;

                continue;
            }

            // Cocokkan PERSIS by username (marketplace search itu fuzzy — jangan asal
            // ambil hasil pertama biar tak salah orang). Tak ada yang cocok → tandai
            // sudah dicek (biar tak diulang) lalu lewati.
            $match = collect($creators)->first(fn ($c) => mb_strtolower(trim((string) ($c['username'] ?? ''))) === $uname);
            if (! $match) {
                $kol->forceFill(['tiktok_checked_at' => now()])->save();
                $skipped++;
                $this->line("  – @{$uname}: tak ada kecocokan persis di marketplace, dilewati.");
            } else {
                $svc->applyCreatorToKol($kol, $match);
                $saved++;
                $this->line("  ✓ @{$uname}: tersimpan.");
            }

            if ($sleep > 0 && $i < $kols->count() - 1) {
                sleep($sleep);
            }
        }

        $this->info("Selesai: {$saved} tersimpan · {$skipped} tak cocok · {$errors} error. Sisa yang belum dicek bisa dilanjut dengan menjalankan ulang.");

        return self::SUCCESS;
    }
}
