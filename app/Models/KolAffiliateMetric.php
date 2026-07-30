<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KolAffiliateMetric extends Model
{
    public const STAGE_REGISTERED = 'registered';

    public const STAGE_ONBOARDING = 'onboarding';

    public const STAGE_CONTENT_ACTIVE = 'content_active';

    public const STAGE_ORDER_ACTIVE = 'order_active';

    public const STAGE_RETAINED = 'retained';

    public const STAGES = [
        self::STAGE_REGISTERED => 'Terdaftar',
        self::STAGE_ONBOARDING => 'Onboarding',
        self::STAGE_CONTENT_ACTIVE => 'Aktif konten/live',
        self::STAGE_ORDER_ACTIVE => 'Menghasilkan order',
        self::STAGE_RETAINED => 'Retained',
    ];

    protected $fillable = [
        'kol_id', 'period_month', 'stage', 'content_count', 'live_count',
        'order_count', 'gmv', 'conversion_rate', 'retention_rate', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_month' => 'date',
            'content_count' => 'integer',
            'live_count' => 'integer',
            'order_count' => 'integer',
            'gmv' => 'decimal:2',
            'conversion_rate' => 'decimal:4',
            'retention_rate' => 'decimal:4',
        ];
    }

    public function kol()
    {
        return $this->belongsTo(Kol::class);
    }
}
