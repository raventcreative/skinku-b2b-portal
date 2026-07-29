<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OkrCycle extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const PERIOD_MONTHLY = 'monthly';

    public const PERIOD_QUARTERLY = 'quarterly';

    public const SCOPE_COMPANY = 'company';

    public const SCOPE_TEAM = 'team';

    public const SCOPE_INDIVIDUAL = 'individual';

    protected $fillable = [
        'name', 'period_type', 'period_label', 'start_date', 'end_date',
        'scope_type', 'scope_name', 'scope_owner_user_id', 'direction',
        'analysis_summary', 'analysis_evidence', 'analysis_assumptions',
        'analysis_conflicts', 'data_coverage',
        'status', 'created_by', 'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'approved_at' => 'datetime',
            'analysis_evidence' => 'array',
            'analysis_assumptions' => 'array',
            'analysis_conflicts' => 'array',
            'data_coverage' => 'array',
        ];
    }

    public function objectives()
    {
        return $this->hasMany(OkrObjective::class)->orderBy('position')->orderBy('id');
    }

    public function scopeOwner()
    {
        return $this->belongsTo(User::class, 'scope_owner_user_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function scopeLabel(): string
    {
        return match ($this->scope_type) {
            self::SCOPE_TEAM => 'Tim: '.($this->scope_name ?: '—'),
            self::SCOPE_INDIVIDUAL => 'Individu: '.($this->scopeOwner?->displayName() ?: '—'),
            default => 'Perusahaan',
        };
    }
}
