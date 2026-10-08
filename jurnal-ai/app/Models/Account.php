<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    public const TYPE_ASSET = 'asset';

    public const TYPE_LIABILITY = 'liability';

    public const TYPE_EQUITY = 'equity';

    public const TYPE_REVENUE = 'revenue';

    public const TYPE_EXPENSE = 'expense';

    public const TYPES = [
        self::TYPE_ASSET => 'Aset',
        self::TYPE_LIABILITY => 'Liabilitas',
        self::TYPE_EQUITY => 'Ekuitas',
        self::TYPE_REVENUE => 'Pendapatan',
        self::TYPE_EXPENSE => 'Beban',
    ];

    /** Tipe yang saldo normalnya di sisi debit. */
    public const DEBIT_TYPES = [self::TYPE_ASSET, self::TYPE_EXPENSE];

    /** Subtype yang dianggap "alat bayar" — kandidat sisi kredit saat belanja. */
    public const PAYMENT_SUBTYPES = ['cash', 'bank', 'ewallet'];

    protected $fillable = [
        'client_id', 'code', 'name', 'type', 'subtype', 'normal_balance', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Akun kas/bank/e-wallet — dipakai sebagai lawan jurnal pembayaran. */
    public function scopePayment($query)
    {
        return $query->whereIn('subtype', self::PAYMENT_SUBTYPES);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function lines()
    {
        return $this->hasMany(JournalLine::class);
    }

    public function isDebitNormal(): bool
    {
        return $this->normal_balance === 'debit';
    }

    public function label(): string
    {
        return $this->code.' — '.$this->name;
    }
}
