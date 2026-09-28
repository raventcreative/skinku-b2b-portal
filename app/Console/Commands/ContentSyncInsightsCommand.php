<?php

namespace App\Console\Commands;

use App\Models\ContentPostSnapshot;
use App\Models\ContentPostTarget;
use App\Models\SocialConnection;
use App\Services\Social\ContentInsights;
use App\Services\Social\MetaClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Tarik metrik postingan terbit dari API platform → snapshot harian (FR-80, FR-81).
 * Jalan ulang di hari yang sama menimpa baris hari itu (bukan menambah).
 * Error insight dicatat di meta koneksi (insight_error) tanpa mengubah status
 * koneksi — publish tetap jalan walau scope insight belum diberikan (FR-84).
 *
 * ponytail: jendela 90 hari & diproses inline satu per satu; pindah ke job/chunk
 * bila postingan terbit sudah ratusan per hari (rate limit Graph ±200 call/jam/user).
 */
class ContentSyncInsightsCommand extends Command
{
    protected $signature = 'content:sync-insights {--days=90 : Hanya postingan yang terbit N hari terakhir}';

    protected $description = 'Tarik metrik (views, likes, komen, share, save) postingan konten brand ke snapshot harian.';

    public function handle(ContentInsights $insights): int
    {
        $targets = ContentPostTarget::with('post')
            ->where('status', ContentPostTarget::PUBLISHED)
            ->whereNotNull('external_id')
            ->where('published_at', '>=', now()->subDays((int) $this->option('days')))
            ->orderBy('id')->get();

        $connections = SocialConnection::all()->keyBy('platform');
        $warnings = [];
        $saved = 0;

        foreach ($targets as $t) {
            $conn = $connections[$t->platform] ?? null;
            if (! $conn || ! $conn->isActive()) {
                continue;
            }

            try {
                [$metrics, $warn] = $insights->fetch($t, $conn);
            } catch (Throwable $e) {
                [$metrics, $warn] = [[], MetaClient::sanitize($e->getMessage())];
            }
            if ($warn) {
                $warnings[$t->platform] = $warn;
                $this->warn("Target #{$t->id} ({$t->platform}): {$warn}");
            }

            $metrics = array_intersect_key($metrics, array_flip(ContentPostSnapshot::METRICS));
            if (array_filter($metrics, fn ($v) => $v !== null) === []) {
                continue;
            }
            ContentPostSnapshot::updateOrCreate(
                ['content_post_target_id' => $t->id, 'captured_on' => today()],
                $metrics,
            );
            $saved++;
        }

        // Peringatan terbaru per platform → ditampilkan di halaman Akun Sosial Media.
        foreach ($connections as $platform => $conn) {
            if ($targets->contains('platform', $platform)) {
                $conn->update(['meta' => array_merge($conn->meta ?? [], ['insight_error' => $warnings[$platform] ?? null])]);
            }
        }

        $this->info("Snapshot disimpan: {$saved} dari {$targets->count()} target.");

        return self::SUCCESS;
    }
}
