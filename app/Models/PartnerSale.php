<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PartnerSale extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_number', 'user_id', 'customer_name', 'total_amount',
        'notes', 'sold_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'sold_at' => 'date',
        ];
    }

    public function items()
    {
        return $this->hasMany(PartnerSaleItem::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
