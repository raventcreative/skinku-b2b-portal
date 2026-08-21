<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PoReturnItem extends Model
{
    protected $table = 'po_return_items';

    protected $fillable = ['po_return_id', 'purchase_order_item_id', 'qty'];

    protected function casts(): array
    {
        return ['qty' => 'integer'];
    }

    public function poItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }
}
