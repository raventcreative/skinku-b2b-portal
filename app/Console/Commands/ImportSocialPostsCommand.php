<?php

namespace App\Console\Commands;

use App\Models\ContentPost;
use App\Models\ContentPostTarget;
use App\Models\SocialConnection;
use App\Services\Social\MetaClient;
use App\Services\Social\TikTokContentClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/** Impor postingan brand yang sudah ada agar dapat masuk ke Insight SKINKU. */
class ImportSocialPostsCommand extends Command
{
    protected $signature = 'content:import-social-posts {--days=90 : Batas umur postingan yang diimpor}';

    protected $description = 'Impor postingan Instagram dan video TikTok publik ke Insight konten.';

    public function handle(MetaClient $meta, TikTokContentClient $tiktok): int
    {
        $days = max(1, min(365, (int) $this->option('days')));
        $since = now()->subDays($days)->startOfDay();
        $imported = 0;
        foreach (['instagram', 'tiktok'] as $platform) {
            $conn = SocialConnection::for($platform);
            if (! $conn?->isActive()) {
                continue;
            }
            $owner = $conn->connected_by;
            if (! $owner) {
                $this->warn("{$platform}: akun tidak memiliki pengguna penghubung; dilewati.");
                continue;
            }
            try {
                $posts = $platform === 'instagram'
                    ? $meta->instagramMedia($conn->account_id, $conn->access_token, $since->toIso8601String())
                    : $tiktok->videoList($tiktok->freshToken($conn), $since->timestamp);
                $conn->update(['meta' => array_merge($conn->meta ?? [], ['insight_error' => null])]);
                foreach ($posts as $item) {
                    $id = (string) ($item['id'] ?? '');
                    if ($id === '' || ContentPostTarget::where('platform', $platform)->where('external_id', $id)->exists()) {
                        continue;
                    }
                    $caption = trim((string) ($platform === 'instagram' ? ($item['caption'] ?? '') : ($item['video_description'] ?? $item['title'] ?? '')));
                    $publishedAt = $platform === 'instagram'
                        ? Carbon::parse($item['timestamp'] ?? now())
                        : Carbon::createFromTimestamp((int) ($item['create_time'] ?? now()->timestamp));
                    $type = $platform === 'tiktok' || ($item['media_type'] ?? '') === 'VIDEO' ? 'video' : 'image';
                    $post = ContentPost::create([
                        'user_id' => $owner,
                        'title' => Str::limit($caption !== '' ? $caption : "{$platform} · {$publishedAt->format('d M Y')}", 120, ''),
                        'type' => $type, 'caption' => $caption, 'status' => ContentPost::DONE,
                    ]);
                    $post->targets()->create([
                        'platform' => $platform, 'status' => ContentPostTarget::PUBLISHED,
                        'external_id' => $id, 'permalink' => $item['permalink'] ?? $item['share_url'] ?? null,
                        'published_at' => $publishedAt, 'options' => ['imported' => true],
                    ]);
                    $imported++;
                }
            } catch (Throwable $e) {
                $error = MetaClient::sanitize($e->getMessage());
                $conn->update(['meta' => array_merge($conn->meta ?? [], ['insight_error' => $error])]);
                $this->warn("{$platform}: {$error}");
            }
        }

        $this->info("Postingan baru diimpor: {$imported}.");
        if ($imported > 0) {
            $this->call('content:sync-insights', ['--days' => $days]);
        }

        return self::SUCCESS;
    }
}
