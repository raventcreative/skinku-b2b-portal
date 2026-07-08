<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaterialPurchase extends Model
{
    protected $fillable = [
        'material_id', 'material_name', 'quantity', 'unit_cost', 'subtotal',
        'cost_before', 'cost_after', 'supplier_name', 'purchased_at', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'purchased_at' => 'date',
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'cost_before' => 'decimal:2',
            'cost_after' => 'decimal:2',
        ];
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
