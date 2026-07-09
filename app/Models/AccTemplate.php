<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccTemplate extends Model
{
    protected $table = 'acc_templates';

    protected $fillable = ['name', 'description', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function lines()
    {
        return $this->hasMany(AccTemplateLine::class)->orderBy('sort_order')->orderBy('id');
    }
}
