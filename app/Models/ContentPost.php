<?php

namespace App\Models;

use App\Models\Concerns\HasFiles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

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

    /** Tahap Pipeline Konten → status post; 'attention' (perlu tindakan) lewat scopePerluTindakan. */
    public const TAHAP_STATUS = [
        'draft' => [self::DRAFT],
        'scheduled' => [self::SCHEDULED],
        'publishing' => [self::PUBLISHING],
        'published' => [self::DONE],
    ];

    /** Pengelola lintas creator (lihat & kelola konten semua creator): super admin / content.manage. */
    public static function lintasCreator(User $user): bool
    {
        return $user->isSuperAdmin() || $user->canDo('content.manage');
    }

    /** Konten yang boleh dilihat di Pipeline & Kalender: pengelola semua creator, creator hanya miliknya. */
    public function scopeTerlihatOleh(Builder $q, User $user): Builder
    {
        return $q->when(! self::lintasCreator($user), fn ($q) => $q->where('user_id', $user->id));
    }

    /** Perlu tindakan: terbit sebagian/gagal, atau ada platform gagal / menunggu posting manual. */
    public function scopePerluTindakan(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->whereIn('status', [self::PARTIAL, self::FAILED])
            ->orWhereHas('targets', fn ($t) => $t->whereIn('status', [ContentPostTarget::FAILED, ContentPostTarget::MANUAL_PENDING])));
    }

    /** Saring tahap Pipeline: all | draft | scheduled | publishing | attention | published (lainnya = semua). */
    public function scopeTahap(Builder $q, string $tahap): Builder
    {
        return match (true) {
            $tahap === 'attention' => $q->perluTindakan(),
            isset(self::TAHAP_STATUS[$tahap]) => $q->whereIn('status', self::TAHAP_STATUS[$tahap]),
            default => $q,
        };
    }

    /**
     * Angka kartu tahap Pipeline Konten untuk user ini (halaman & alat AI pipeline_konten).
     *
     * @return array{per_status:Collection<string,int>,perlu_tindakan:int}
     */
    public static function hitungTahap(User $user): array
    {
        return [
            'per_status' => self::query()->terlihatOleh($user)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'perlu_tindakan' => self::query()->terlihatOleh($user)->perluTindakan()->count(),
        ];
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
