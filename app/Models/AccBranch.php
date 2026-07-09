<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccBranch extends Model
{
    protected $table = 'acc_branches';

    protected $fillable = ['code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function journals()
    {
        return $this->hasMany(AccJournal::class, 'branch_id');
    }
}
