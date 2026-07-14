<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TiktokOrder extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DEDUCTED = 'deducted';

    public const STATUS_SKIPPED = 'skipped';

    /** Status TikTok yang dianggap "barang sudah keluar" (layak potong stok). */
    public const SHIPPED_STATUSES = ['AWAITING_COLLECTION', 'IN_TRANSIT', 'DELIVERED', 'COMPLETED'];

    protected $fillable = [
        'tiktok_order_id', 'status', 'total_amount', 'currency', 'line_items',
        'stock_status', 'order_created_at', 'deducted_at', 'deducted_by',
    ];

    protected $casts = [
        'line_items' => 'array',
        'total_amount' => 'decimal:2',
        'order_created_at' => 'datetime',
        'deducted_at' => 'datetime',
    ];

    /** "Barang keluar" — order sudah dikirim/selesai. */
    public function isShipped(): bool
    {
        return in_array($this->status, self::SHIPPED_STATUSES, true);
    }
}
