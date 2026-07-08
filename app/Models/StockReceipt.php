<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockReceipt extends Model
{
    /** reference_type value written on the stock_movements ledger. */
    public const REFERENCE_TYPE = 'stock_receipt';

    protected $fillable = [
        'receipt_number', 'supplier_name', 'reference_no',
        'received_at', 'total_cost', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'date',
            'total_cost' => 'decimal:2',
        ];
    }

    public function items()
    {
        return $this->hasMany(StockReceiptItem::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
