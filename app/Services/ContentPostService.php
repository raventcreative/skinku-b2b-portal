<?php

namespace App\Services;

use App\Models\ContentPost;
use App\Models\ContentPostTarget;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Alur Content Pipeline: simpan draft, terbitkan/jadwalkan, retry dan catat
 * posting manual. Semua transisi publikasi dijaga di service ini.
 */
class ContentPostService
{
    public function __construct(private ImageService $images) {}

    /**
     * Cek kelengkapan sebelum publikasi: media cocok dengan tipe & platform,
     * caption tak melebihi batas tiap platform.
     *
     * @param  array<int,array{mime:string,size:int}>  $media  size dalam byte
     * @param  array<int,string>  $platforms
     * @param  array<string,?string>  $captions  override per platform
     * @return array<int,string> daftar pesan error (kosong = lolos)
     */
    public static function submitErrors(string $type, array $media, ?string $caption, array $platforms, array $captions = []): array
    {
        $errors = [];
        $images = array_filter($media, fn ($m) => str_starts_with($m['mime'], 'image/'));
        // application/mp4 = kontainer MP4 yang dideteksi server tanpa label video (umum di file HP).
        $videos = array_filter($media, fn ($m) => str_starts_with($m['mime'], 'video/') || $m['mime'] === 'application/mp4');
        $min = config('content.carousel_min');
        $max = config('content.carousel_max');

        $n = count($media);
        if ($type === 'image' && ! ($n === 1 && count($images) === 1)) {
            $errors[] = 'Tipe Foto butuh tepat 1 gambar.';
        } elseif ($type === 'video' && ! ($n === 1 && count($videos) === 1)) {
            $errors[] = 'Tipe Video butuh tepat 1 video (MP4/MOV).';
        } elseif ($type === 'carousel' && ! ($n === count($images) && $n >= $min && $n <= $max)) {
            $errors[] = "Carousel butuh {$min}–{$max} gambar (tanpa video).";
        } elseif (! array_key_exists($type, ContentPost::TYPES)) {
            $errors[] = 'Tipe konten tidak dikenal.';
        }

        $imageMax = config('content.image_max_kb') * 1024;
        foreach ($images as $m) {
            if ($m['size'] > $imageMax) {
                $errors[] = 'Gambar maksimal '.intdiv($imageMax, 1024 * 1024).' MB per file.';
                break;
            }
        }

        if ($platforms === []) {
            $errors[] = 'Pilih minimal 1 platform.';
        }

        foreach ($platforms as $p) {
            $cfg = config("content.platforms.{$p}");
            if (! $cfg) {
                $errors[] = "Platform {$p} tidak dikenal.";

                continue;
            }
            if (! in_array($type, $cfg['types'], true)) {
                $errors[] = "{$cfg['label']} tidak mendukung tipe ".(ContentPost::TYPES[$type] ?? $type).'.';
            }
            $text = (string) (($captions[$p] ?? null) ?: $caption);
            if (mb_strlen($text) > $cfg['caption_max']) {
                $errors[] = "Caption {$cfg['label']} maksimal {$cfg['caption_max']} karakter (sekarang ".mb_strlen($text).').';
            }
            if (isset($cfg['hashtag_max']) && preg_match_all('/#[\p{L}\p{N}_]+/u', $text) > $cfg['hashtag_max']) {
                $errors[] = "{$cfg['label']} maksimal {$cfg['hashtag_max']} hashtag.";
            }
        }

        return $errors;
    }

    /** @param  array<int,UploadedFile>  $files */
    public static function describeUploads(array $files): array
    {
        return array_map(fn (UploadedFile $f) => ['mime' => (string) $f->getMimeType(), 'size' => (int) $f->getSize()], $files);
    }

    public static function describeStored(ContentPost $post): array
    {
        return $post->filesIn(ContentPost::MEDIA)->get()
            ->map(fn ($f) => ['mime' => (string) $f->mime_type, 'size' => (int) $f->size])->all();
    }

    /**
     * Buat / perbarui konten milik creator. Upload baru MENGGANTI semua media lama.
     *
     * @param  array<int,UploadedFile>  $files
     */
    public function save(?ContentPost $post, User $user, array $data, array $files): ContentPost
    {
        return DB::transaction(function () use ($post, $user, $data, $files) {
            $attrs = [
                'title' => $data['title'],
                'type' => $data['type'],
                'caption' => $data['caption'] ?? null,
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'creator_note' => $data['creator_note'] ?? null,
            ];

            if ($post === null) {
                $post = ContentPost::create($attrs + ['user_id' => $user->id, 'status' => ContentPost::DRAFT]);
                $action = 'content.create';
            } else {
                $post->update($attrs);
                $action = 'content.update';
            }

            if ($files !== []) {
                $post->filesIn(ContentPost::MEDIA)->get()->each->delete();
                foreach ($files as $file) {
                    // 1080px: batas foto TikTok (maks 1080p) sekaligus ukuran rekomendasi Instagram.
                    $this->images->attach($post, $file, ContentPost::MEDIA, 1080);
                }
            }

            $this->syncTargets($post, $data['platforms'] ?? [], $data['captions'] ?? []);

            if ($post->wasRecentlyCreated === false && $data['intent'] === 'draft' && $post->status !== ContentPost::DRAFT) {
                $post->update(['status' => ContentPost::DRAFT]);
                $post->targets()->whereIn('status', [ContentPostTarget::QUEUED, ContentPostTarget::MANUAL_PENDING])
                    ->update(['status' => ContentPostTarget::PENDING, 'next_attempt_at' => null]);
            }

            AuditService::log(action: $action, targetType: 'content_post', targetId: $post->id,
                after: ['title' => $post->title, 'type' => $post->type, 'platforms' => $data['platforms'] ?? []]);

            return $post;
        });
    }

    /** Masukkan seluruh target ke antrean publikasi atau jalur posting manual. */
    public function publish(ContentPost $post, array $tiktok = []): void
    {
        $this->assertStatus($post, [ContentPost::DRAFT, ContentPost::SCHEDULED], 'diterbitkan');
        if ($post->status === ContentPost::SCHEDULED && ! $post->isEditable()) {
            throw ValidationException::withMessages(['status' => 'Konten yang mulai diproses tidak dapat diterbitkan ulang.']);
        }
        $this->assertComplete($post);
        $targets = $post->targets;
        if ($targets->isEmpty()) {
            throw ValidationException::withMessages(['platforms' => 'Pilih minimal satu platform.']);
        }

        DB::transaction(function () use ($post, $targets, $tiktok) {
            foreach ($targets as $target) {
                $manual = $target->isManual();
                if ($target->platform === 'tiktok' && ! $manual) {
                    if (empty($tiktok['privacy_level']) || empty($tiktok['consent'])) {
                        throw ValidationException::withMessages(['tiktok' => 'Pilih pengaturan privasi TikTok dan setujui Music Usage Confirmation.']);
                    }
                    if (! array_key_exists($tiktok['privacy_level'], \App\Services\Social\TikTokContentClient::PRIVACY_LABELS)) {
                        throw ValidationException::withMessages(['tiktok.privacy_level' => 'Pilihan privasi TikTok tidak valid.']);
                    }
                    $target->options = [
                        'privacy_level' => $tiktok['privacy_level'],
                        'allow_comment' => ! empty($tiktok['allow_comment']),
                        'allow_duet' => ! empty($tiktok['allow_duet']),
                        'allow_stitch' => ! empty($tiktok['allow_stitch']),
                        'brand_organic' => ! empty($tiktok['brand_organic']),
                        'music_usage_consent_at' => now()->toIso8601String(),
                    ];
                }
                $target->fill([
                    'status' => $manual ? ContentPostTarget::MANUAL_PENDING : ContentPostTarget::QUEUED,
                    'attempts' => 0, 'next_attempt_at' => null, 'last_error' => null,
                    'container_id' => null, 'container_polls' => 0,
                ])->save();
            }
            $post->recomputeStatus();
            AuditService::log(action: 'content.publish', targetType: 'content_post', targetId: $post->id,
                after: ['scheduled_at' => $post->scheduled_at?->toDateTimeString(), 'platforms' => $targets->pluck('platform')->all()]);
        });
    }

    /** Retry manual untuk target API yang gagal. */
    public function retry(ContentPostTarget $target): void
    {
        if ($target->status !== ContentPostTarget::FAILED || $target->isManual()) {
            throw ValidationException::withMessages(['target' => 'Hanya target API berstatus Gagal yang bisa di-retry.']);
        }

        $target->update(['status' => ContentPostTarget::QUEUED, 'attempts' => 0, 'next_attempt_at' => null, 'container_id' => null, 'container_polls' => 0]);
        $target->post->recomputeStatus();
        AuditService::log(action: 'content.retry', targetType: 'content_post', targetId: $target->content_post_id, after: ['platform' => $target->platform]);
    }

    /** Catat permalink untuk target manual atau target API yang diposting di luar portal. */
    public function markPublished(ContentPostTarget $target, string $url): void
    {
        if (! in_array($target->status, [ContentPostTarget::MANUAL_PENDING, ContentPostTarget::FAILED], true)) {
            throw ValidationException::withMessages(['target' => 'Target ini tidak menunggu posting manual.']);
        }

        $target->update(['status' => ContentPostTarget::PUBLISHED, 'permalink' => $url, 'published_at' => now(), 'last_error' => null]);
        $target->post->recomputeStatus();
        AuditService::log(action: 'content.mark_published', targetType: 'content_post', targetId: $target->content_post_id,
            after: ['platform' => $target->platform, 'url' => $url]);
    }

    private function syncTargets(ContentPost $post, array $platforms, array $captions): void
    {
        $post->targets()->whereNotIn('platform', $platforms)->delete();
        foreach ($platforms as $p) {
            $post->targets()->updateOrCreate(['platform' => $p], ['caption_override' => ($captions[$p] ?? '') ?: null]);
        }
        $post->unsetRelation('targets');
    }

    private function assertStatus(ContentPost $post, array $allowed, string $verb): void
    {
        if (! in_array($post->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => "Konten berstatus \"{$post->statusLabel()}\" tidak bisa {$verb}."]);
        }
    }

    private function assertComplete(ContentPost $post, ?array $captions = null): void
    {
        $captions ??= $post->targets->pluck('caption_override', 'platform')->all();
        $errors = self::submitErrors($post->type, self::describeStored($post), $post->caption, array_keys($captions), $captions);
        if ($errors !== []) {
            throw ValidationException::withMessages(['content' => $errors]);
        }
    }
}
