<?php

namespace App\Services\Social;

use App\Models\ContentPostTarget;
use App\Models\SocialConnection;
use RuntimeException;
use Throwable;

/**
 * Ambil metrik satu target terbit dari API platform (FR-80, FR-81) → kunci
 * seragam views/reach/likes/comments/shares/saves (null = platform tak menyediakan).
 *
 * Metrik dasar (like/komen) memakai scope publish yang sudah ada; metrik insight
 * (views/reach/saves) butuh scope insight baru. Kalau scope insight belum ada
 * (akun terhubung sebelum Fase 3), metrik dasar tetap disimpan dan pesan error
 * insight dikembalikan sebagai peringatan — koneksi TIDAK diputus (FR-84).
 *
 * @return array{0:array<string,?int>,1:?string} [metrik, peringatan]
 */
class ContentInsights
{
    public function __construct(private MetaClient $meta, private TikTokContentClient $tiktok) {}

    public function fetch(ContentPostTarget $target, SocialConnection $conn): array
    {
        $id = (string) $target->external_id;

        return match ($target->platform) {
            'facebook' => $this->facebook($id, $target->post->type, $conn->access_token),
            'instagram' => $this->instagram($id, $conn->access_token),
            'threads' => $this->threads($id, $conn->access_token),
            'tiktok' => $this->tiktok($id, $conn),
            default => throw new RuntimeException("Insight {$target->platform} belum didukung."),
        };
    }

    private function facebook(string $id, string $type, string $token): array
    {
        $base = $this->meta->graphBase();
        // Objek video tak punya field shares.
        $fields = 'reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0)'.($type === 'video' ? '' : ',shares');
        $node = $this->meta->get($base, "/{$id}", $token, ['fields' => $fields]);
        $m = [
            'likes' => $node['reactions']['summary']['total_count'] ?? null,
            'comments' => $node['comments']['summary']['total_count'] ?? null,
            'shares' => $type === 'video' ? null : ($node['shares']['count'] ?? 0),
        ];

        // ponytail: nama metrik views FB sering diganti Meta (impressions → media_view, 2025);
        // cek ulang di changelog Graph API bila views FB selalu kosong.
        [$views, $warn] = $this->tryInsights(fn () => $type === 'video'
            ? $this->meta->get($base, "/{$id}/video_insights", $token, ['metric' => 'total_video_views'])
            : $this->meta->get($base, "/{$id}/insights", $token, ['metric' => 'post_media_view']));

        return [$m + ['views' => $views['total_video_views'] ?? $views['post_media_view'] ?? null], $warn];
    }

    private function instagram(string $id, string $token): array
    {
        $base = $this->meta->graphBase();
        $node = $this->meta->get($base, "/{$id}", $token, ['fields' => 'like_count,comments_count']);
        $m = ['likes' => $node['like_count'] ?? null, 'comments' => $node['comments_count'] ?? null];

        [$ins, $warn] = $this->tryInsights(fn () => $this->meta->get($base, "/{$id}/insights", $token, ['metric' => 'views,reach,shares,saved']));

        return [$m + ['views' => $ins['views'] ?? null, 'reach' => $ins['reach'] ?? null,
            'shares' => $ins['shares'] ?? null, 'saves' => $ins['saved'] ?? null], $warn];
    }

    private function threads(string $id, string $token): array
    {
        // Threads tak punya hitungan like/komen di luar endpoint insight.
        [$ins, $warn] = $this->tryInsights(fn () => $this->meta->get(MetaClient::THREADS_BASE, "/{$id}/insights", $token,
            ['metric' => 'views,likes,replies,reposts,quotes,shares']));
        if ($ins === []) {
            return [[], $warn];
        }

        return [['views' => $ins['views'] ?? null, 'likes' => $ins['likes'] ?? null, 'comments' => $ins['replies'] ?? null,
            'shares' => ($ins['reposts'] ?? 0) + ($ins['quotes'] ?? 0) + ($ins['shares'] ?? 0)], $warn];
    }

    private function tiktok(string $id, SocialConnection $conn): array
    {
        try {
            $v = $this->tiktok->videoStats($this->tiktok->freshToken($conn), [$id])[$id] ?? null;
        } catch (Throwable $e) {
            return [[], MetaClient::sanitize($e->getMessage())];
        }

        return [$v ? ['views' => $v['view_count'] ?? null, 'likes' => $v['like_count'] ?? null,
            'comments' => $v['comment_count'] ?? null, 'shares' => $v['share_count'] ?? null] : [], null];
    }

    /**
     * Panggil endpoint insight Graph ({data:[{name, values:[{value}] | total_value:{value}}]}).
     *
     * @return array{0:array<string,int>,1:?string} [nama→nilai, peringatan]
     */
    private function tryInsights(callable $call): array
    {
        try {
            $out = [];
            foreach ($call()['data'] ?? [] as $row) {
                $out[$row['name']] = (int) ($row['values'][0]['value'] ?? $row['total_value']['value'] ?? 0);
            }

            return [$out, null];
        } catch (Throwable $e) {
            return [[], MetaClient::sanitize($e->getMessage())];
        }
    }
}
