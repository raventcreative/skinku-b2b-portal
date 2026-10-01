<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Snapshot performa TikTok Creator Marketplace satu KOL (1:1) — diisi dari
 * halaman "Cek Performa TikTok". Lihat migrasi 000121.
 */
class KolTiktokProfile extends Model
{
    protected $fillable = [
        'kol_id', 'open_id', 'followers', 'gmv_usd', 'gmv_idr', 'gmv_range',
        'video_gmv_idr', 'live_gmv_idr', 'avg_video_views', 'avg_live_uv',
        'region', 'gender', 'gender_pct', 'age_ranges', 'usd_idr_rate', 'synced_at',
        'video_count', 'live_count', 'video_engagement_pct', 'live_engagement_pct', 'gpm_idr',
        'units_sold', 'brand_collab_count', 'avg_commission_pct', 'performance_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'followers' => 'integer',
            'gmv_usd' => 'float',
            'gmv_idr' => 'integer',
            'video_gmv_idr' => 'integer',
            'live_gmv_idr' => 'integer',
            'avg_video_views' => 'integer',
            'avg_live_uv' => 'integer',
            'gender_pct' => 'float',
            'usd_idr_rate' => 'integer',
            'synced_at' => 'datetime',
            'video_count' => 'integer',
            'live_count' => 'integer',
            'video_engagement_pct' => 'float',
            'live_engagement_pct' => 'float',
            'gpm_idr' => 'integer',
            'units_sold' => 'integer',
            'brand_collab_count' => 'integer',
            'avg_commission_pct' => 'float',
            'performance_synced_at' => 'datetime',
        ];
    }

    public function kol()
    {
        return $this->belongsTo(Kol::class);
    }
}
