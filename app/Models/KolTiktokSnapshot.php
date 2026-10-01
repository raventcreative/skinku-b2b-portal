<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Riwayat performa TikTok satu KOL per tanggal sync (tracker Detail KOL). Lihat migrasi 000157. */
class KolTiktokSnapshot extends Model
{
    protected $fillable = [
        'kol_id', 'captured_on', 'followers', 'avg_video_views', 'video_count', 'live_count',
        'video_engagement_pct', 'live_engagement_pct', 'gmv_idr', 'video_gmv_idr', 'live_gmv_idr',
        'gpm_idr', 'units_sold',
    ];

    protected function casts(): array
    {
        return [
            'captured_on' => 'date',
            'followers' => 'integer',
            'avg_video_views' => 'integer',
            'video_count' => 'integer',
            'live_count' => 'integer',
            'video_engagement_pct' => 'float',
            'live_engagement_pct' => 'float',
            'gmv_idr' => 'integer',
            'video_gmv_idr' => 'integer',
            'live_gmv_idr' => 'integer',
            'gpm_idr' => 'integer',
            'units_sold' => 'integer',
        ];
    }
}
