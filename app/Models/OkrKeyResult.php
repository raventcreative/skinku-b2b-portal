<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OkrKeyResult extends Model
{
    protected $fillable = [
        'okr_objective_id', 'title', 'metric', 'target',
        'owner_user_id', 'due_date', 'position',
    ];

    protected function casts(): array
    {
        return ['due_date' => 'date'];
    }

    public function objective()
    {
        return $this->belongsTo(OkrObjective::class, 'okr_objective_id');
    }

    public function tasks()
    {
        return $this->hasMany(OkrTask::class)->orderBy('position')->orderBy('id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
