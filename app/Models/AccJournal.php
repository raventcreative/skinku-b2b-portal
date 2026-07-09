<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccJournal extends Model
{
    protected $table = 'acc_journals';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_VOID = 'void';

    protected $fillable = [
        'branch_id', 'date', 'period', 'reference', 'description',
        'type', 'status', 'source_type', 'source_id',
    ];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function branch()
    {
        return $this->belongsTo(AccBranch::class, 'branch_id');
    }

    public function lines()
    {
        return $this->hasMany(AccJournalLine::class, 'journal_id');
    }

    public function totalDebit(): float
    {
        return (float) $this->lines->sum('debit');
    }

    public function totalCredit(): float
    {
        return (float) $this->lines->sum('credit');
    }

    public function isBalanced(): bool
    {
        return round($this->totalDebit(), 2) === round($this->totalCredit(), 2);
    }
}
