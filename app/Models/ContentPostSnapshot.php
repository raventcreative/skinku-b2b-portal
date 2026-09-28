<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Metrik harian satu target terbit (FR-80) — diisi content:sync-insights, satu baris per hari. */
class ContentPostSnapshot extends Model
{
    public const METRICS = ['views', 'reach', 'likes', 'comments', 'shares', 'saves'];

    protected $fillable = ['content_post_target_id', 'captured_on', 'views', 'reach', 'likes', 'comments', 'shares', 'saves'];

    protected function casts(): array
    {
        return ['captured_on' => 'date'];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(ContentPostTarget::class, 'content_post_target_id');
    }

    public function interactions(): int
    {
        return (int) $this->likes + (int) $this->comments + (int) $this->shares + (int) $this->saves;
    }

    /** Engagement rate % = interaksi ÷ views (sama dengan KOL Konten & Views). Null bila views kosong. */
    public function er(): ?float
    {
        return $this->views ? $this->interactions() / $this->views * 100 : null;
    }
}
