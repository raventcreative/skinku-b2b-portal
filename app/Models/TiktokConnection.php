<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TiktokConnection extends Model
{
    protected $fillable = [
        'shop_id', 'shop_cipher', 'shop_name', 'region', 'seller_name',
        'access_token', 'refresh_token', 'access_expires_at', 'refresh_expires_at',
        'connected_by', 'last_synced_at',
    ];

    protected $casts = [
        'access_expires_at' => 'datetime',
        'refresh_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    public function accessExpiringSoon(): bool
    {
        // refresh kalau < 5 menit lagi (atau sudah lewat)
        return $this->access_expires_at === null || $this->access_expires_at->subMinutes(5)->isPast();
    }
}
