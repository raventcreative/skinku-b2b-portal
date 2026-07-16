<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopeeConnection extends Model
{
    protected $fillable = [
        'shop_id', 'shop_name', 'region',
        'access_token', 'refresh_token', 'access_expires_at', 'refresh_expires_at',
        'connected_by', 'last_synced_at', 'auto_deduct', 'deduct_from',
    ];

    protected $casts = [
        'access_expires_at' => 'datetime',
        'refresh_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'auto_deduct' => 'boolean',
        'deduct_from' => 'date',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    /**
     * Token Shopee cuma berlaku ~4 jam (TikTok 7 hari), jadi ambang refresh
     * dibuat lebih lebar: 10 menit sebelum kedaluwarsa.
     */
    public function accessExpiringSoon(): bool
    {
        return $this->access_expires_at === null || $this->access_expires_at->subMinutes(10)->isPast();
    }

    /** Sinkron basi = cron mati / izin dicabut. Jadwal normal tiap 30 menit. */
    public function syncStale(int $hours = 2): bool
    {
        return $this->last_synced_at === null || $this->last_synced_at->lt(now()->subHours($hours));
    }
}
