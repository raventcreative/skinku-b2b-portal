<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Journal extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_VOID = 'void';

    protected $fillable = [
        'client_id', 'document_id', 'date', 'period', 'reference',
        'description', 'type', 'status', 'fingerprint', 'created_by',
    ];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function scopePosted($query)
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function lines()
    {
        return $this->hasMany(JournalLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function totalDebit(): float
    {
        return round((float) $this->lines->sum('debit'), 2);
    }

    public function totalCredit(): float
    {
        return round((float) $this->lines->sum('credit'), 2);
    }

    public function isBalanced(): bool
    {
        return abs($this->totalDebit() - $this->totalCredit()) < 0.005;
    }

    /** Bulan buku yang punya jurnal posted, terbaru dulu. Kosong → bulan ini. */
    public static function periodsFor(int $clientId): array
    {
        $periods = static::query()->where('client_id', $clientId)->posted()
            ->distinct()->orderByDesc('period')->pluck('period')->all();

        return $periods ?: [now()->format('Y-m')];
    }
}
