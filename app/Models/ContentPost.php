<?php

namespace App\Models;

use App\Models\Concerns\HasFiles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Konten dari content creator → review admin → terbit ke akun brand per platform
 * (status per platform ada di ContentPostTarget). Alur status: FRD §4.
 */
class ContentPost extends Model
{
    use HasFiles, SoftDeletes;

    public const MEDIA = 'content_media';

    public const DRAFT = 'draft';

    public const IN_REVIEW = 'in_review';

    public const REJECTED = 'rejected';

    public const SCHEDULED = 'scheduled';

    public const PUBLISHING = 'publishing';

    public const DONE = 'done';

    public const PARTIAL = 'partial';

    public const FAILED = 'failed';

    public const STATUS_LABELS = [
        self::DRAFT => 'Draft',
        self::IN_REVIEW => 'Menunggu Review',
        self::REJECTED => 'Ditolak',
        self::SCHEDULED => 'Terjadwal',
        self::PUBLISHING => 'Sedang Terbit',
        self::DONE => 'Terbit',
        self::PARTIAL => 'Terbit Sebagian',
        self::FAILED => 'Gagal',
    ];

    public const TYPES = ['image' => 'Foto', 'video' => 'Video', 'carousel' => 'Carousel'];

    protected $fillable = [
        'user_id', 'title', 'type', 'caption', 'status', 'scheduled_at', 'creator_note',
        'review_note', 'reviewed_by', 'reviewed_at', 'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(ContentPostTarget::class);
    }

    /** Creator hanya boleh mengubah draft / konten yang ditolak (FR-25). */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::REJECTED], true);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /**
     * Status konten diturunkan dari status target (FR-33). "Menunggu" (queued,
     * publishing, manual_pending) → masih jalan; semuanya selesai → done/partial/failed.
     */
    public function recomputeStatus(): void
    {
        $statuses = $this->targets()->pluck('status');
        if ($statuses->isEmpty()) {
            return;
        }

        $waiting = $statuses->intersect([ContentPostTarget::QUEUED, ContentPostTarget::PUBLISHING, ContentPostTarget::MANUAL_PENDING]);
        $published = $statuses->filter(fn ($s) => $s === ContentPostTarget::PUBLISHED)->count();

        if ($waiting->isNotEmpty()) {
            $started = $published > 0 || $statuses->contains(ContentPostTarget::FAILED) || $statuses->contains(ContentPostTarget::PUBLISHING);
            $status = $started ? self::PUBLISHING : self::SCHEDULED;
        } elseif ($published === $statuses->count()) {
            $status = self::DONE;
        } else {
            $status = $published > 0 ? self::PARTIAL : self::FAILED;
        }

        $this->forceFill(['status' => $status])->save();
    }
}
