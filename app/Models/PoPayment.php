<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu cicilan/pembayaran parsial atas sebuah PO — dicatat admin saat uang masuk. */
class PoPayment extends Model
{
    protected $fillable = ['purchase_order_id', 'amount', 'paid_at', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['paid_at' => 'date', 'amount' => 'float'];
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
