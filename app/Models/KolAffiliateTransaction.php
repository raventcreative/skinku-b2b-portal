<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KolAffiliateTransaction extends Model
{
    /** Status yang dianggap batal → dikecualikan dari GMV. */
    public const CANCELLED = ['batal', 'cancelled', 'canceled', 'dibatalkan'];

    protected $fillable = [
        'platform', 'order_id', 'kol_id', 'raw_username', 'gmv', 'commission', 'commission_settled',
        'qty', 'product', 'status', 'content_type', 'order_date', 'source', 'created_by',
    ];

    protected function casts(): array
    {
        return ['order_date' => 'date'];
    }

    public function kol()
    {
        return $this->belongsTo(Kol::class);
    }

    public function scopeMatched($q)
    {
        return $q->whereNotNull('kol_id');
    }

    public function scopeUnmatched($q)
    {
        return $q->whereNull('kol_id');
    }

    /** GMV yang dihitung = semua kecuali status batal (null status ikut dihitung). */
    public function scopeNotCancelled($q)
    {
        return $q->where(fn ($w) => $w->whereNull('status')
            ->orWhereRaw('LOWER(status) NOT IN (?, ?, ?, ?)', self::CANCELLED));
    }
}
