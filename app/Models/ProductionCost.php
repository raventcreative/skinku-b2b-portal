<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionCost extends Model
{
    protected $fillable = ['production_id', 'label', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function production()
    {
        return $this->belongsTo(Production::class);
    }
}
