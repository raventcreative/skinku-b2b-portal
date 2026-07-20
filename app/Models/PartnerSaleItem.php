<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PartnerSaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'partner_sale_id', 'product_id', 'product_name', 'qty', 'unit_price', 'total_price',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'unit_price' => 'decimal:2',
            'total_price' => 'decimal:2',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
