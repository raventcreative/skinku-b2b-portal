<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductDevelopment extends Model
{
    public const STAGES = [
        'research' => 'Riset',
        'concept' => 'Konsep',
        'costing' => 'Costing / HPP',
        'sampling' => 'Sampling',
        'market_test' => 'Uji pasar',
        'production' => 'Produksi',
        'launch' => 'Launch',
        'evaluation' => 'Evaluasi',
    ];

    protected $fillable = [
        'name', 'category', 'stage', 'owner_user_id',
        'target_launch_date', 'product_id', 'notes',
    ];

    protected function casts(): array
    {
        return ['target_launch_date' => 'date'];
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function stageLabel(): string
    {
        return self::STAGES[$this->stage] ?? $this->stage;
    }
}
