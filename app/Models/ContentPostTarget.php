<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu platform tujuan dari sebuah ContentPost — status, ID eksternal & retry sendiri-sendiri. */
class ContentPostTarget extends Model
{
    public const PENDING = 'pending';

    public const QUEUED = 'queued';

    public const PUBLISHING = 'publishing';

    public const PUBLISHED = 'published';

    public const FAILED = 'failed';

    public const MANUAL_PENDING = 'manual_pending';

    public const STATUS_LABELS = [
        self::PENDING => 'Menunggu persetujuan',
        self::QUEUED => 'Antre',
        self::PUBLISHING => 'Sedang terbit',
        self::PUBLISHED => 'Terbit',
        self::FAILED => 'Gagal',
        self::MANUAL_PENDING => 'Siap posting manual',
    ];

    protected $fillable = [
        'content_post_id', 'platform', 'caption_override', 'options', 'status', 'external_id', 'container_id',
        'container_polls', 'permalink', 'published_at', 'attempts', 'next_attempt_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'published_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'attempts' => 'integer',
            'container_polls' => 'integer',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(ContentPost::class, 'content_post_id');
    }

    public function caption(): string
    {
        return (string) ($this->caption_override ?? $this->post->caption);
    }

    public function platformLabel(): string
    {
        return config("content.platforms.{$this->platform}.label", $this->platform);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** Manual = admin posting sendiri & tempel link. Mode auto → manual selama akun platform belum terhubung. */
    public function isManual(): bool
    {
        return match (config("content.platforms.{$this->platform}.mode")) {
            'manual' => true,
            'auto' => ! SocialConnection::for($this->platform)?->isActive(),
            default => false,
        };
    }
}
