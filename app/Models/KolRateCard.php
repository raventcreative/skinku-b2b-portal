<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Rate card per tipe konten untuk satu KOL (append-only → jadi riwayat). */
class KolRateCard extends Model
{
    public const UPDATED_AT = null;

    public const TYPES = ['tiktok_video', 'reels', 'story', 'live', 'bundle', 'other'];

    public const TYPE_LABELS = [
        'tiktok_video' => 'Video TikTok', 'reels' => 'Reels IG', 'story' => 'Story IG',
        'live' => 'Live', 'bundle' => 'Bundle / paket', 'other' => 'Lainnya',
    ];

    protected $fillable = ['kol_id', 'content_type', 'rate', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['rate' => 'integer'];
    }

    public function kol()
    {
        return $this->belongsTo(Kol::class);
    }
}
