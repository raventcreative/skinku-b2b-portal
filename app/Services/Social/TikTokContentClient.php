<?php

namespace App\Services\Social;

use App\Models\SocialConnection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * TikTok Content Posting API (Direct Post) + Login Kit — FR-43, FR-54.
 * Dok: developers.tiktok.com/doc/content-posting-api-reference-direct-post.
 * - Video: FILE_UPLOAD (upload langsung dari server, tanpa verifikasi domain).
 * - Foto : hanya PULL_FROM_URL → domain APP_URL wajib diverifikasi di developer portal.
 * App belum lolos audit TikTok → semua postingan dipaksa private (SELF_ONLY).
 */
class TikTokContentClient
{
    public const API = 'https://open.tiktokapis.com/v2';

    public const SCOPES = ['user.info.basic', 'video.publish', 'video.upload'];

    public const PRIVACY_LABELS = [
        'PUBLIC_TO_EVERYONE' => 'Publik',
        'MUTUAL_FOLLOW_FRIENDS' => 'Teman (saling follow)',
        'FOLLOWER_OF_CREATOR' => 'Follower',
        'SELF_ONLY' => 'Hanya saya (private)',
    ];

    public const CHUNK = 10 * 1024 * 1024; // 5–64 MB diizinkan; potongan terakhir boleh s/d 128 MB

    public function configured(): bool
    {
        return filled(config('services.tiktok_content.client_key')) && filled(config('services.tiktok_content.client_secret'));
    }

    public function authorizeUrl(string $redirect, string $state): string
    {
        return 'https://www.tiktok.com/v2/auth/authorize/?'.http_build_query([
            'client_key' => config('services.tiktok_content.client_key'),
            'scope' => implode(',', self::SCOPES),
            'response_type' => 'code',
            'redirect_uri' => $redirect,
            'state' => $state,
        ]);
    }

    /** @return array{access_token:string,expires_in:int,open_id:string,refresh_token:string,refresh_expires_in:int} */
    public function exchangeCode(string $code, string $redirect): array
    {
        return $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirect]);
    }

    public function refresh(string $refreshToken): array
    {
        return $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    /** Pastikan token akses masih berlaku (umur 24 jam) — perbarui pakai refresh token bila perlu. */
    public function freshToken(SocialConnection $conn): string
    {
        if ($conn->access_expires_at && $conn->access_expires_at->lt(now()->addMinutes(10))) {
            $t = $this->refresh((string) $conn->refresh_token);
            $conn->update([
                'access_token' => $t['access_token'], 'refresh_token' => $t['refresh_token'],
                'access_expires_at' => now()->addSeconds($t['expires_in']), 'status' => 'active', 'last_error' => null,
            ]);
        }

        return $conn->access_token;
    }

    public function userInfo(string $token): array
    {
        return $this->decode(Http::withToken($token)->get(self::API.'/user/info/', ['fields' => 'open_id,display_name,avatar_url']))['user'] ?? [];
    }

    /** @return array{creator_nickname?:string,creator_username?:string,privacy_level_options?:array,comment_disabled?:bool,duet_disabled?:bool,stitch_disabled?:bool,max_video_post_duration_sec?:int} */
    public function creatorInfo(string $token): array
    {
        return $this->decode(Http::withToken($token)->asJson()->post(self::API.'/post/publish/creator_info/query/'));
    }

    /**
     * Pembagian potongan upload sesuai aturan TikTok: < 5 MB → satu potongan utuh;
     * selain itu potongan 10 MB, jumlah = floor(size/chunk), sisa ikut potongan terakhir.
     *
     * @return array{0:int,1:int} [chunk_size, total_chunk_count]
     */
    public static function chunkPlan(int $size): array
    {
        if ($size < 5 * 1024 * 1024) {
            return [$size, 1];
        }

        return [self::CHUNK, max(1, intdiv($size, self::CHUNK))];
    }

    /** Init Direct Post video (FILE_UPLOAD) lalu upload berurutan. @return string publish_id */
    public function postVideo(string $token, array $postInfo, string $path, string $mime): string
    {
        $size = filesize($path);
        if (! $size) {
            throw new RuntimeException('File video kosong / tidak ditemukan di server.');
        }
        [$chunk, $count] = self::chunkPlan($size);

        $init = $this->decode(Http::withToken($token)->asJson()->post(self::API.'/post/publish/video/init/', [
            'post_info' => $postInfo,
            'source_info' => ['source' => 'FILE_UPLOAD', 'video_size' => $size, 'chunk_size' => $chunk, 'total_chunk_count' => $count],
        ]));

        $fh = fopen($path, 'rb');
        try {
            for ($i = 0; $i < $count; $i++) {
                $start = $i * $chunk;
                $end = $i === $count - 1 ? $size - 1 : $start + $chunk - 1;
                fseek($fh, $start);
                $body = fread($fh, $end - $start + 1);

                $res = Http::timeout(300)->withHeaders([
                    'Content-Range' => "bytes {$start}-{$end}/{$size}",
                ])->withBody($body, $mime)->put($init['upload_url']);

                if ($res->failed()) {
                    throw new RuntimeException('TikTok upload potongan '.($i + 1)."/{$count} gagal (HTTP {$res->status()}).");
                }
            }
        } finally {
            fclose($fh);
        }

        return (string) $init['publish_id'];
    }

    /** Init Direct Post foto (PULL_FROM_URL — domain harus terverifikasi). @return string publish_id */
    public function postPhotos(string $token, array $postInfo, array $urls): string
    {
        return (string) $this->decode(Http::withToken($token)->asJson()->post(self::API.'/post/publish/content/init/', [
            'post_info' => $postInfo,
            'source_info' => ['source' => 'PULL_FROM_URL', 'photo_cover_index' => 0, 'photo_images' => array_values($urls)],
            'post_mode' => 'DIRECT_POST',
            'media_type' => 'PHOTO',
        ]))['publish_id'];
    }

    /** @return array{status:string,fail_reason?:string,publicaly_available_post_id?:array} */
    public function status(string $token, string $publishId): array
    {
        return $this->decode(Http::withToken($token)->asJson()->post(self::API.'/post/publish/status/fetch/', ['publish_id' => $publishId]));
    }

    private function token(array $params): array
    {
        $res = Http::asForm()->post(self::API.'/oauth/token/', $params + [
            'client_key' => config('services.tiktok_content.client_key'),
            'client_secret' => config('services.tiktok_content.client_secret'),
        ]);
        $json = $res->json() ?? [];
        if ($res->failed() || empty($json['access_token'])) {
            throw new RuntimeException('TikTok OAuth: '.($json['error_description'] ?? $json['error'] ?? 'HTTP '.$res->status()));
        }

        return $json;
    }

    /** Envelope TikTok: {data:{...}, error:{code:"ok"|..., message}}. */
    private function decode($response): array
    {
        $json = $response->json() ?? [];
        $code = $json['error']['code'] ?? ($response->failed() ? 'http_'.$response->status() : 'ok');
        if ($code !== 'ok') {
            $message = $json['error']['message'] ?? '';
            throw new RuntimeException('TikTok API: '.$code.($message !== '' ? ' — '.$message : ''));
        }

        return $json['data'] ?? [];
    }
}
