<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeOrder extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DEDUCTED = 'deducted';

    /** Status Shopee yang berarti "barang sudah keluar gudang" (layak potong stok). */
    public const SHIPPED_STATUSES = ['SHIPPED', 'TO_CONFIRM_RECEIVE', 'COMPLETED'];

    /** Status yang berarti "sudah sampai pembeli" (dipakai saat pengakuan penjualan). */
    public const DELIVERED_STATUSES = ['COMPLETED'];

    /**
     * Order berbayar yang masih berjalan (belum selesai) — untuk estimasi bulanan.
     * UNPAID/INVOICE_PENDING sengaja TIDAK dihitung (belum tentu jadi).
     */
    public const PIPELINE_STATUSES = ['READY_TO_SHIP', 'PROCESSED', 'SHIPPED', 'TO_CONFIRM_RECEIVE', 'RETRY_SHIP'];

    /** Status batal — tidak akan pernah dikirim. */
    public const CANCELLED_STATUSES = ['CANCELLED', 'IN_CANCEL'];

    protected $fillable = [
        'order_sn', 'status', 'total_amount', 'hpp_amount', 'currency', 'line_items',
        'stock_status', 'order_created_at', 'deducted_at', 'deducted_by',
    ];

    protected $casts = [
        'line_items' => 'array',
        'total_amount' => 'decimal:2',
        'hpp_amount' => 'decimal:2',
        'order_created_at' => 'datetime',
        'deducted_at' => 'datetime',
    ];

    public function isShipped(): bool
    {
        return in_array($this->status, self::SHIPPED_STATUSES, true);
    }

    public function isCancelled(): bool
    {
        return in_array($this->status, self::CANCELLED_STATUSES, true);
    }
}
