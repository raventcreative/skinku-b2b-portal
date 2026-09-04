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
        {--sleep=7 : Jeda detik antar panggilan (rate limit marketplace ~10/menit → jangan terlalu kecil)}
        {--cooldown=90 : Detik menunggu saat kena rate limit sebelum coba lagi}
        {--retries=3 : Berapa kali menunggu-lalu-coba-lagi saat rate limit sebelum menyerah}
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
        $cooldown = max(1, (int) $this->option('cooldown'));
        $retries = max(0, (int) $this->option('retries'));
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

        $this->info("Proses {$kols->count()} KOL (limit {$limit}, jeda {$sleep}s, cooldown {$cooldown}s×{$retries})…");
        $saved = $skipped = $errors = 0;
        $lastIndex = $kols->count() - 1;

        foreach ($kols as $i => $kol) {
            $uname = mb_strtolower(trim((string) $kol->tiktok_username));

            // Ambil dgn auto-backoff: kalau kena rate limit, TUNGGU cooldown lalu coba
            // lagi (bukan langsung nyerah) — biar 1 run bisa nembus jendela per-menit.
            $creators = null;
            $tries = 0;
            while ($creators === null) {
                try {
                    $creators = $svc->searchCreators($conn, (string) $kol->tiktok_username, 12);
                } catch (\Throwable $e) {
                    $limited = str_contains($e->getMessage(), '36009002') || stripos($e->getMessage(), 'too many requests') !== false;
                    // Kalau kena limit SEJAK panggilan pertama (belum ada yg tersimpan),
                    // kuota memang lagi HABIS (blokir panjang, bukan jendela per-menit) →
                    // keluar cepat, tak usah buang waktu nunggu cooldown percuma.
                    if ($limited && $saved === 0 && $i === 0) {
                        $this->warn('Kuota marketplace TikTok lagi HABIS (kena limit dari panggilan pertama). Ini BUKAN error — TikTok lagi membatasi karena kebanyakan permintaan. Tunggu beberapa jam / besok pagi, JANGAN diulang cepat-cepat (malah bikin makin lama diblokir).');

                        return self::SUCCESS;
                    }
                    if ($limited && $tries < $retries) {
                        $tries++;
                        $this->warn("  … rate limit — tunggu {$cooldown}s lalu coba lagi (#{$tries}/{$retries})…");
                        sleep($cooldown);

                        continue;
                    }
                    if ($limited) {
                        $this->warn("Rate limit TikTok belum reda setelah {$retries}× tunggu (~".($retries * $cooldown).'s). BERHENTI — jalankan lagi nanti; yang sudah tersimpan dilewati.');
                        $this->info("Selesai (berhenti di rate limit): {$saved} tersimpan · {$skipped} tak cocok · {$errors} error.");

                        return self::SUCCESS;
                    }
                    $this->warn("@{$uname}: gagal — {$e->getMessage()}");
                    $errors++;
                    break; // creators tetap null → lewati KOL ini
                }
            }
            if ($creators === null) {
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

            if ($sleep > 0 && $i < $lastIndex) {
                sleep($sleep);
            }
        }

        $this->info("Selesai: {$saved} tersimpan · {$skipped} tak cocok · {$errors} error. Sisa yang belum dicek bisa dilanjut dengan menjalankan ulang.");

        return self::SUCCESS;
    }
}
