<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OkrObjective extends Model
{
    protected $fillable = [
        'okr_cycle_id', 'title', 'description', 'owner_user_id', 'position',
    ];

    public function cycle()
    {
        return $this->belongsTo(OkrCycle::class, 'okr_cycle_id');
    }

    public function keyResults()
    {
        return $this->hasMany(OkrKeyResult::class)->orderBy('position')->orderBy('id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
