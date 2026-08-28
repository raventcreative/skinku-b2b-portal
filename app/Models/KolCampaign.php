<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Campaign payung untuk beberapa deal KOL. Rollup dihitung di controller. */
class KolCampaign extends Model
{
    public const PLATFORMS = ['tiktok', 'shopee', 'instagram', 'multi'];

    public const PLATFORM_LABELS = ['tiktok' => 'TikTok', 'shopee' => 'Shopee', 'instagram' => 'Instagram', 'multi' => 'Multi-platform'];

    public const STATUSES = ['planned', 'active', 'done'];

    public const STATUS_LABELS = ['planned' => 'Rencana', 'active' => 'Berjalan', 'done' => 'Selesai'];

    /** Urutan tampil: berjalan → rencana → selesai. */
    public const STATUS_ORDER = ['active' => 0, 'planned' => 1, 'done' => 2];

    protected $fillable = [
        'name', 'platform', 'start_date', 'end_date', 'target_views', 'target_gmv', 'status', 'notes', 'created_by',
    ];

    protected $attributes = ['platform' => 'multi', 'status' => 'active'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'target_views' => 'integer', 'target_gmv' => 'integer'];
    }

    public function deals()
    {
        return $this->hasMany(KolDeal::class, 'kol_campaign_id');
    }
}
