<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = ['name', 'label', 'is_system', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** Urut sesuai sort_order (lihat migrasi reorder role), lalu id. */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function users()
    {
        return $this->hasMany(User::class, 'role', 'name');
    }
}
