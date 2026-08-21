<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VolumeIncentiveTier extends Model
{
    protected $fillable = ['threshold', 'rate_percent', 'is_active'];

    protected function casts(): array
    {
        return [
            'threshold' => 'decimal:2',
            'rate_percent' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
