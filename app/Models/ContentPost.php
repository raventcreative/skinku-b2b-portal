<?php

namespace App\Models;

use App\Models\Concerns\HasFiles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Konten creator → antre/jadwal → terbit ke akun brand per platform.
 * Status per platform ada di ContentPostTarget.
 */
class ContentPost extends Model
{
    use HasFiles, SoftDeletes;

    public const MEDIA = 'content_media';

    public const DRAFT = 'draft';

    /** @deprecated Status lama dimigrasikan ke draft. */
    public const IN_REVIEW = 'in_review';

    /** @deprecated Status lama dimigrasikan ke draft. */
    public const REJECTED = 'rejected';

    public const SCHEDULED = 'scheduled';

    public const PUBLISHING = 'publishing';

    public const DONE = 'done';

    public const PARTIAL = 'partial';

    public const FAILED = 'failed';

    public const STATUS_LABELS = [
        self::DRAFT => 'Draft',
        self::IN_REVIEW => 'Legacy approval',
        self::REJECTED => 'Legacy rejection',
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

    /** Draft dan item antrean yang belum mulai terbit masih dapat diperbaiki. */
    public function isEditable(): bool
    {
        if ($this->status === self::DRAFT) {
            return true;
        }

        if ($this->status !== self::SCHEDULED) {
            return false;
        }

        $targets = $this->relationLoaded('targets') ? $this->targets : $this->targets()->get(['id', 'status']);

        return ! $targets->contains(fn ($target) => in_array($target->status, [ContentPostTarget::PUBLISHING, ContentPostTarget::PUBLISHED], true));
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /**
     * Status konten diturunkan dari status target. Target yang menunggu (queued,
     * publishing, manual_pending) membuat item tetap aktif.
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
