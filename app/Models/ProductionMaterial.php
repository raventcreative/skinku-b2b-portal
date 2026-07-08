<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionMaterial extends Model
{
    protected $fillable = [
        'production_id', 'material_id', 'material_name', 'unit',
        'quantity', 'unit_cost', 'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function production()
    {
        return $this->belongsTo(Production::class);
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }
}
