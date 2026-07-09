<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccAccount extends Model
{
    protected $table = 'acc_accounts';

    public const TYPE_ASSET = 'asset';

    public const TYPE_LIABILITY = 'liability';

    public const TYPE_EQUITY = 'equity';

    public const TYPE_REVENUE = 'revenue';

    public const TYPE_EXPENSE = 'expense';

    public const TYPES = [
        self::TYPE_ASSET, self::TYPE_LIABILITY, self::TYPE_EQUITY,
        self::TYPE_REVENUE, self::TYPE_EXPENSE,
    ];

    /** Account types whose normal balance is debit. */
    public const DEBIT_TYPES = [self::TYPE_ASSET, self::TYPE_EXPENSE];

    protected $fillable = [
        'code', 'name', 'type', 'subtype', 'normal_balance', 'legacy_code', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Cash & bank accounts — used as the counter-account for bank-mutation import. */
    public function scopeCashLike($query)
    {
        return $query->whereIn('subtype', ['cash', 'bank']);
    }

    public function isDebitNormal(): bool
    {
        return $this->normal_balance === 'debit';
    }

    public function lines()
    {
        return $this->hasMany(AccJournalLine::class, 'account_id');
    }
}
