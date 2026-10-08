<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccJournal extends Model
{
    protected $table = 'acc_journals';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    /** Bulan buku yang punya jurnal posted, terbaru dulu (dropdown periode Akuntansi + alat AI). Kosong → bulan ini. */
    public static function periodeBuku(): array
    {
        $periods = static::query()->where('status', self::STATUS_POSTED)->distinct()->orderByDesc('period')->pluck('period')->all();

        return $periods ?: [now()->format('Y-m')];
    }

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
        return abs($this->totalDebit() - $this->totalCredit()) < 0.005;
    }
}
