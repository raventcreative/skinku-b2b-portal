<?php

namespace App\Services\Social;

use App\Models\ContentPost;
use App\Models\ContentPostTarget;
use App\Models\SocialConnection;
use RuntimeException;

/**
 * Menerbitkan satu target ke platformnya (FR-51..53).
 * - Facebook: sinkron (foto / video via file_url / multi-foto lewat attached_media).
 * - Instagram & Threads: container → tunggu FINISHED → publish. Video butuh waktu
 *   proses, jadi hasil 'pending' berarti "cek lagi nanti" (container_id disimpan).
 * Media diambil platform dari URL publik HTTPS (APP_URL harus domain publik).
 *
 * @return array{status:'published',external_id:string,permalink:?string}|array{status:'pending',container_id:string}
 */
class ContentPublisher
{
    public function __construct(private MetaClient $meta) {}

    public function publish(ContentPostTarget $target): array
    {
        $conn = SocialConnection::for($target->platform);
        if (! $conn || ! $conn->isActive()) {
            throw new RuntimeException('Akun '.$target->platformLabel().' belum terhubung / token kedaluwarsa. Hubungkan ulang di menu Akun Sosial Media.');
        }

        $post = $target->post;
        $urls = $post->fileUrls(ContentPost::MEDIA);
        if ($urls === []) {
            throw new RuntimeException('Konten tidak punya media.');
        }

        return match ($target->platform) {
            'facebook' => $this->facebook($conn, $post->type, $urls, $target->caption()),
            'instagram' => $this->viaContainer($target, $conn, $urls, [
                'base' => $this->meta->graphBase(), 'create' => 'media', 'publish' => 'media_publish',
                'caption' => 'caption', 'video' => 'REELS', 'status' => 'status_code', 'error' => 'status', 'child_type' => false,
            ]),
            'threads' => $this->viaContainer($target, $conn, $urls, [
                'base' => MetaClient::THREADS_BASE, 'create' => 'threads', 'publish' => 'threads_publish',
                'caption' => 'text', 'video' => 'VIDEO', 'status' => 'status', 'error' => 'error_message', 'child_type' => true,
            ]),
            default => throw new RuntimeException("Platform {$target->platform} belum didukung publikasi API."),
        };
    }

    private function facebook(SocialConnection $conn, string $type, array $urls, string $caption): array
    {
        $base = $this->meta->graphBase();
        $page = $conn->account_id;
        $token = $conn->access_token;

        if ($type === 'video') {
            $id = $this->meta->post($base, "/{$page}/videos", $token, ['file_url' => $urls[0], 'description' => $caption])['id'];
        } elseif ($type === 'carousel') {
            $media = [];
            foreach ($urls as $url) {
                $photo = $this->meta->post($base, "/{$page}/photos", $token, ['url' => $url, 'published' => 'false']);
                $media[] = json_encode(['media_fbid' => $photo['id']]);
            }
            $id = $this->meta->post($base, "/{$page}/feed", $token, ['message' => $caption, 'attached_media' => $media])['id'];
        } else {
            $res = $this->meta->post($base, "/{$page}/photos", $token, ['url' => $urls[0], 'message' => $caption]);
            $id = $res['post_id'] ?? $res['id'];
        }

        $link = $this->meta->get($base, "/{$id}", $token, ['fields' => 'permalink_url'])['permalink_url'] ?? null;
        if ($link && str_starts_with($link, '/')) {
            $link = 'https://www.facebook.com'.$link;
        }

        return ['status' => 'published', 'external_id' => (string) $id, 'permalink' => $link];
    }

    private function viaContainer(ContentPostTarget $target, SocialConnection $conn, array $urls, array $spec): array
    {
        $base = $spec['base'];
        $uid = $conn->account_id;
        $token = $conn->access_token;
        $type = $target->post->type;

        $container = $target->container_id;
        if (! $container) {
            $params = [$spec['caption'] => $target->caption()];
            if ($type === 'video') {
                $params += ['media_type' => $spec['video'], 'video_url' => $urls[0]];
            } elseif ($type === 'carousel') {
                $children = [];
                foreach ($urls as $url) {
                    $child = ['image_url' => $url, 'is_carousel_item' => 'true'] + ($spec['child_type'] ? ['media_type' => 'IMAGE'] : []);
                    $children[] = $this->meta->post($base, "/{$uid}/{$spec['create']}", $token, $child)['id'];
                }
                $params += ['media_type' => 'CAROUSEL', 'children' => implode(',', $children)];
            } else {
                $params += ['image_url' => $urls[0]] + ($spec['child_type'] ? ['media_type' => 'IMAGE'] : []);
            }

            $container = (string) $this->meta->post($base, "/{$uid}/{$spec['create']}", $token, $params)['id'];
            $target->update(['container_id' => $container]);
        }

        $check = $this->meta->get($base, "/{$container}", $token, ['fields' => $spec['status'].','.$spec['error']]);
        $status = strtoupper((string) ($check[$spec['status']] ?? ''));
        if (in_array($status, ['ERROR', 'EXPIRED'], true)) {
            throw new RuntimeException('Platform menolak media: '.($check[$spec['error']] ?? $status));
        }
        if ($status !== 'FINISHED' && $status !== 'PUBLISHED') {
            return ['status' => 'pending', 'container_id' => $container];
        }

        $id = (string) $this->meta->post($base, "/{$uid}/{$spec['publish']}", $token, ['creation_id' => $container])['id'];
        $link = $this->meta->get($base, "/{$id}", $token, ['fields' => 'permalink'])['permalink'] ?? null;

        return ['status' => 'published', 'external_id' => $id, 'permalink' => $link];
    }
}
