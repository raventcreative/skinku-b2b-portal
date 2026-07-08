<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockReceiptItem extends Model
{
    protected $fillable = [
        'stock_receipt_id', 'product_id', 'product_name',
        'quantity', 'unit_cost', 'subtotal', 'cogs_before', 'cogs_after',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'cogs_before' => 'decimal:2',
            'cogs_after' => 'decimal:2',
        ];
    }

    public function receipt()
    {
        return $this->belongsTo(StockReceipt::class, 'stock_receipt_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
