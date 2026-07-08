<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Production extends Model
{
    /** reference_type value written on the finished-product stock_movements row. */
    public const REFERENCE_TYPE = 'production';

    protected $fillable = [
        'production_number', 'product_id', 'product_name', 'produced_at', 'output_qty',
        'material_cost', 'other_cost', 'total_cost', 'hpp_per_unit',
        'cogs_before', 'cogs_after', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'produced_at' => 'date',
            'output_qty' => 'integer',
            'material_cost' => 'decimal:2',
            'other_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'hpp_per_unit' => 'decimal:2',
            'cogs_before' => 'decimal:2',
            'cogs_after' => 'decimal:2',
        ];
    }

    public function materials()
    {
        return $this->hasMany(ProductionMaterial::class);
    }

    public function costs()
    {
        return $this->hasMany(ProductionCost::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
