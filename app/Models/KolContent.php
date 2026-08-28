<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KolContent extends Model
{
    public const TYPES = ['video', 'reels', 'story', 'live', 'feed', 'other'];

    public const TYPE_LABELS = ['video' => 'Video', 'reels' => 'Reels', 'story' => 'Story', 'live' => 'Live', 'feed' => 'Feed', 'other' => 'Lainnya'];

    protected $fillable = ['kol_id', 'kol_deal_id', 'platform', 'content_type', 'url', 'title', 'thumbnail_url', 'notes', 'label', 'posted_at', 'created_by'];

    /** Default in-memory (platform TikTok, earned) agar terisi tanpa refresh DB. */
    protected $attributes = ['platform' => 'tiktok', 'label' => 'earned'];

    protected function casts(): array
    {
        return ['posted_at' => 'date'];
    }

    /** Engagement rate = (like+komen+share+save) ÷ views × 100, dari snapshot terbaru. */
    public function getEngagementRateAttribute(): ?float
    {
        $s = $this->latestSnapshot;
        if (! $s || (int) $s->views <= 0) {
            return null;
        }
        $hasEng = $s->likes !== null || $s->comments !== null || $s->shares !== null || $s->saves !== null;
        if (! $hasEng) {
            return null;
        }
        $eng = (int) $s->likes + (int) $s->comments + (int) $s->shares + (int) $s->saves;

        return round($eng / (int) $s->views * 100, 2);
    }

    /** CPM konten = biaya deal ÷ views × 1000 (hanya konten paid ber-deal). */
    public function getCpmAttribute(): ?int
    {
        $s = $this->latestSnapshot;
        $cost = (int) ($this->deal->total_biaya ?? 0);
        if (! $s || (int) $s->views <= 0 || $cost <= 0) {
            return null;
        }

        return (int) round($cost / (int) $s->views * 1000);
    }

    public function kol()
    {
        return $this->belongsTo(Kol::class);
    }

    public function deal()
    {
        return $this->belongsTo(KolDeal::class, 'kol_deal_id');
    }

    public function snapshots()
    {
        return $this->hasMany(KolContentSnapshot::class);
    }

    /** Snapshot views terbaru — sumber kolom "views" di daftar & ringkasan. */
    public function latestSnapshot()
    {
        return $this->hasOne(KolContentSnapshot::class)->latestOfMany('captured_on');
    }
}
