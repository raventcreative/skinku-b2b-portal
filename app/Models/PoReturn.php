<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PoReturn extends Model
{
    protected $table = 'po_returns';

    protected $fillable = [
        'purchase_order_id', 'status', 'kondisi', 'from_customer', 'reason',
        'requested_by', 'approved_by', 'applied_at',
    ];

    protected function casts(): array
    {
        return ['applied_at' => 'datetime', 'from_customer' => 'boolean'];
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function items()
    {
        return $this->hasMany(PoReturnItem::class, 'po_return_id');
    }
}
