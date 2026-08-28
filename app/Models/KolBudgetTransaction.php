<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pengeluaran budget endorse tambahan (di luar deal). Diagregat ke "spent"
 * bulan yang sama oleh KolBudgetService.
 */
class KolBudgetTransaction extends Model
{
    public const CATEGORIES = ['fee', 'sample', 'gift', 'boost', 'other'];

    public const CATEGORY_LABEL = [
        'fee' => 'Fee tambahan',
        'sample' => 'Sampel / ongkir',
        'gift' => 'Hadiah',
        'boost' => 'Boost iklan',
        'other' => 'Lain-lain',
    ];

    protected $fillable = ['month', 'category', 'amount', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function categoryLabel(): string
    {
        return self::CATEGORY_LABEL[$this->category] ?? $this->category;
    }
}
