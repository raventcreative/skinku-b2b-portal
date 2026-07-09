<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccJournalLine extends Model
{
    protected $table = 'acc_journal_lines';

    protected $fillable = [
        'journal_id', 'account_id', 'branch_id', 'debit', 'credit', 'memo',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
        ];
    }

    public function journal()
    {
        return $this->belongsTo(AccJournal::class, 'journal_id');
    }

    public function account()
    {
        return $this->belongsTo(AccAccount::class, 'account_id');
    }

    public function branch()
    {
        return $this->belongsTo(AccBranch::class, 'branch_id');
    }
}
