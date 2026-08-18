<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JoinPackage extends Model
{
    protected $fillable = ['name', 'target_role', 'price', 'is_active'];

    protected $casts = ['price' => 'decimal:2', 'is_active' => 'boolean'];

    public function items(): HasMany
    {
        return $this->hasMany(JoinPackageItem::class);
    }
}
