<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Material extends Model
{
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'name', 'unit', 'stock', 'avg_cost', 'status', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'stock' => 'decimal:3',
            'avg_cost' => 'decimal:2',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function purchases()
    {
        return $this->hasMany(MaterialPurchase::class);
    }
}
