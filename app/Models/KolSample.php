<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Sampel produk untuk deal KOL. Subtotal HPP = units × unit_cost. */
class KolSample extends Model
{
    public const STATUSES = ['pending', 'shipped', 'received'];

    public const STATUS_LABELS = ['pending' => 'Belum kirim', 'shipped' => 'Dikirim', 'received' => 'Diterima'];

    protected $fillable = [
        'kol_deal_id', 'kol_id', 'product', 'units', 'unit_cost', 'courier',
        'tracking_no', 'status', 'shipped_at', 'received_at', 'notes', 'created_by',
    ];

    protected $attributes = ['status' => 'pending', 'units' => 1, 'unit_cost' => 0];

    protected function casts(): array
    {
        return ['shipped_at' => 'date', 'received_at' => 'date', 'units' => 'integer', 'unit_cost' => 'integer'];
    }

    /** HPP total baris sampel ini. */
    public function getSubtotalAttribute(): int
    {
        return (int) $this->units * (int) $this->unit_cost;
    }

    public function deal()
    {
        return $this->belongsTo(KolDeal::class, 'kol_deal_id');
    }

    public function kol()
    {
        return $this->belongsTo(Kol::class);
    }
}
