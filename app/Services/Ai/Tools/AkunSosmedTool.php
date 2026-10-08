<?php

namespace App\Services\Ai\Tools;

use App\Models\SocialConnection;
use App\Models\User;
use App\Services\Social\MetaClient;
use App\Services\Social\TikTokContentClient;
use App\Support\SocialCredentials;
use Illuminate\Support\Str;

/**
 * Alat BACA: Akun Sosial Media brand (menu Akun Sosial Media), izin social.connect sama dgn halamannya. Status
 * koneksi per platform + kredensial app terisi/belum. Token, refresh token, account_id & nilai kredensial TIDAK PERNAH dikirim.
 */
class AkunSosmedTool extends BaseTool
{
    public function __construct(private MetaClient $meta, private TikTokContentClient $tiktok) {}

    public function name(): string
    {
        return 'akun_sosmed';
    }

    public function permission(): ?string
    {
        return 'social.connect';
    }

    public function description(): string
    {
        return 'Akun Sosial Media brand SKINKU (menu Akun Sosial Media): per platform (Facebook Page, Instagram Business, '
            .'Threads, TikTok) — terhubung atau belum, nama akun, aktif/bermasalah, masa berlaku token, error terakhir, '
            .'error izin insight; plus kredensial app Meta/Threads/TikTok sudah diisi atau belum. Pakai untuk "kenapa konten '
            .'gagal terbit / insight kosong / akun mana yang perlu dihubungkan ulang".';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'required' => []];
    }

    public function run(array $args, User $user): array
    {
        $koneksi = SocialConnection::with('connectedBy')->get()->keyBy('platform');
        $platform = ['facebook' => 'Facebook Page', 'instagram' => 'Instagram Business', 'threads' => 'Threads', 'tiktok' => 'TikTok'];

        return [
            'akun' => collect($platform)->map(function ($label, $key) use ($koneksi) {
                $c = $koneksi[$key] ?? null;
                if (! $c) {
                    return ['platform' => $label, 'terhubung' => false]
                        + ($key === 'tiktok' ? ['catatan' => 'Selama belum terhubung, konten TikTok diposting manual oleh admin.'] : []);
                }

                return array_filter([
                    'platform' => $label,
                    'terhubung' => true,
                    'akun' => $c->account_name, // account_id (ID Page/open_id) sengaja tidak dikirim
                    'status' => $c->isActive() ? 'aktif' : 'bermasalah — perlu dihubungkan ulang',
                    'token_berlaku_sampai' => $c->access_expires_at?->toDateString(),
                    'token_segera_habis' => $c->isActive() && $c->expiringSoon() ? true : null,
                    'error_terakhir' => ! $c->isActive() && $c->last_error ? Str::limit($c->last_error, 200) : null,
                    'error_insight' => filled($c->meta['insight_error'] ?? null) ? Str::limit((string) $c->meta['insight_error'], 200) : null,
                    'dihubungkan_oleh' => $c->connectedBy?->displayName(),
                    'diperbarui' => $c->updated_at?->toDateString(),
                ], fn ($v) => $v !== null);
            })->values()->all(),
            'aplikasi_siap' => [
                'meta' => $this->meta->metaConfigured(),
                'threads' => $this->meta->threadsConfigured(),
                'tiktok' => $this->tiktok->configured(),
            ],
            // Hanya terisi/belum + sumbernya — nilai (termasuk ID publik) tak pernah dikirim.
            'kredensial_app' => collect(SocialCredentials::status())->map(fn ($c) => [
                'kredensial' => $c['label'],
                'status' => $c['source'] ? 'terisi ('.$c['source'].')' : 'belum diisi',
            ])->values()->all(),
        ];
    }
}
