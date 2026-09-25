<?php

namespace App\Console\Commands;

use App\Models\SocialConnection;
use App\Services\Social\MetaClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Perpanjang token akun brand sebelum kedaluwarsa (FR-45). Threads long-lived
 * token ±60 hari → diperpanjang bila sisa < 7 hari. Page token Facebook/IG yang
 * diturunkan dari token user long-lived tidak kedaluwarsa, jadi tak disentuh.
 */
class SocialRefreshTokensCommand extends Command
{
    protected $signature = 'social:refresh-tokens';

    protected $description = 'Perpanjang token akun sosial media brand (Threads) sebelum kedaluwarsa.';

    public function handle(MetaClient $meta): int
    {
        $conn = SocialConnection::for('threads');
        if (! $conn || ! $conn->expiringSoon()) {
            $this->info('Tidak ada token yang perlu diperpanjang.');

            return self::SUCCESS;
        }

        try {
            $res = $meta->refreshThreadsToken($conn->access_token);
            $conn->update(['access_token' => $res['token'], 'access_expires_at' => now()->addSeconds($res['expires_in']),
                'status' => 'active', 'last_error' => null]);
            $this->info('Token Threads diperpanjang.');
        } catch (Throwable $e) {
            $conn->update(['status' => 'error', 'last_error' => MetaClient::sanitize($e->getMessage())]);
            $this->error('Gagal memperpanjang token Threads: '.MetaClient::sanitize($e->getMessage()));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
