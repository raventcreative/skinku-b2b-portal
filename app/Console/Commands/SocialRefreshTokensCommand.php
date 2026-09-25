<?php

namespace App\Console\Commands;

use App\Models\SocialConnection;
use App\Services\Social\MetaClient;
use App\Services\Social\TikTokContentClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Perpanjang token akun brand sebelum kedaluwarsa (FR-45).
 * - Threads: long-lived ±60 hari → diperpanjang bila sisa < 7 hari.
 * - TikTok : akses 24 jam (juga diperbarui on-demand saat publish), refresh token
 *   365 hari → diperbarui harian supaya koneksi tak pernah basi.
 * Page token Facebook/IG dari token user long-lived tidak kedaluwarsa.
 */
class SocialRefreshTokensCommand extends Command
{
    protected $signature = 'social:refresh-tokens';

    protected $description = 'Perpanjang token akun sosial media brand (Threads, TikTok) sebelum kedaluwarsa.';

    public function handle(MetaClient $meta, TikTokContentClient $tiktok): int
    {
        $ok = true;

        $threads = SocialConnection::for('threads');
        if ($threads && $threads->expiringSoon()) {
            $ok = $this->attempt($threads, function () use ($meta, $threads) {
                $res = $meta->refreshThreadsToken($threads->access_token);
                $threads->update(['access_token' => $res['token'], 'access_expires_at' => now()->addSeconds($res['expires_in'])]);
            }) && $ok;
        }

        $tt = SocialConnection::for('tiktok');
        if ($tt && filled($tt->refresh_token)) {
            $ok = $this->attempt($tt, function () use ($tiktok, $tt) {
                $t = $tiktok->refresh($tt->refresh_token);
                $tt->update(['access_token' => $t['access_token'], 'refresh_token' => $t['refresh_token'],
                    'access_expires_at' => now()->addSeconds($t['expires_in'])]);
            }) && $ok;
        }

        $this->info('Selesai memeriksa token.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function attempt(SocialConnection $conn, callable $refresh): bool
    {
        try {
            $refresh();
            $conn->update(['status' => 'active', 'last_error' => null]);
            $this->info("Token {$conn->platform} diperpanjang.");

            return true;
        } catch (Throwable $e) {
            $error = MetaClient::sanitize($e->getMessage());
            $conn->update(['status' => 'error', 'last_error' => $error]);
            $this->error("Gagal memperpanjang token {$conn->platform}: {$error}");

            return false;
        }
    }
}
