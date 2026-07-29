<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OkrObjective extends Model
{
    protected $fillable = [
        'okr_cycle_id', 'specialist', 'title', 'description', 'rationale',
        'owner_user_id', 'owner_name', 'position',
    ];

    public const SPECIALISTS = [
        'cmo' => 'CMO',
        'cfo' => 'CFO',
        'coo' => 'COO',
    ];

    public function specialistLabel(): string
    {
        return self::SPECIALISTS[$this->specialist] ?? 'Lintas Divisi';
    }

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

    public function ownerLabel(): ?string
    {
        return $this->owner_name ?: $this->owner?->displayName();
    }
}
