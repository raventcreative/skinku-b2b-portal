<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Akun sosial media brand SKINKU yang dihubungkan admin (satu per platform).
 * Token terenkripsi di DB dan tak pernah ikut serialisasi.
 */
class SocialConnection extends Model
{
    protected $fillable = [
        'platform', 'account_id', 'account_name', 'access_token', 'refresh_token',
        'access_expires_at', 'status', 'last_error', 'meta', 'connected_by',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_expires_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    public static function for(string $platform): ?self
    {
        return static::where('platform', $platform)->first();
    }

    public function isActive(): bool
    {
        // Token akses kedaluwarsa masih bisa dipakai bila ada refresh token (TikTok: akses 24 jam, refresh 365 hari).
        return $this->status === 'active' && (! $this->isExpired() || filled($this->refresh_token));
    }

    public function isExpired(): bool
    {
        return $this->access_expires_at !== null && $this->access_expires_at->isPast();
    }

    public function expiringSoon(int $days = 7): bool
    {
        return $this->access_expires_at !== null && $this->access_expires_at->lt(now()->addDays($days));
    }
}
