<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Kol extends Model
{
    use SoftDeletes;

    public const STATUS_PROSPEK = 'prospek';

    public const STATUS_AKTIF = 'aktif';

    public const STATUS_HOLD = 'hold';

    public const STATUS_NON_AKTIF = 'non_aktif';

    public const STATUSES = [self::STATUS_PROSPEK, self::STATUS_AKTIF, self::STATUS_HOLD, self::STATUS_NON_AKTIF];

    protected $fillable = [
        'tiktok_username', 'tiktok_link', 'followers', 'kategori', 'provinsi', 'status', 'catatan',
    ];

    protected function casts(): array
    {
        return ['followers' => 'integer'];
    }

    /**
     * Level = turunan murni dari followers, TIDAK disimpan (dua sumber kebenaran
     * bakal selisih saat followers di-update).
     *
     * Batas mengikuti brief: Nano <10rb · Mikro 10rb–100rb · Middle 100rb–500rb ·
     * Makro 500rb–1jt · Mega 1jt–2,5jt · Super Mega >2,5jt. Angka batas masuk ke
     * jenjang ATASNYA (10.000 = Mikro, 100.000 = Middle, dst) — kecuali 2,5jt yang
     * masih Mega karena rentang Mega tertulis "1jt–2,5jt" (inklusif).
     */
    public function getLevelAttribute(): string
    {
        $f = (int) $this->followers;

        return match (true) {
            $f < 10_000 => 'Nano',
            $f < 100_000 => 'Mikro',
            $f < 500_000 => 'Middle',
            $f < 1_000_000 => 'Makro',
            $f <= 2_500_000 => 'Mega',
            default => 'Super Mega',
        };
    }

    public function screenings()
    {
        return $this->hasMany(KolScreening::class)->orderByDesc('tanggal_listing')->orderByDesc('id');
    }

    public function deals()
    {
        return $this->hasMany(KolDeal::class)->orderByDesc('id');
    }

    /** Screening terbaru — sumber kolom "verdict terakhir" di daftar KOL. */
    public function latestScreening()
    {
        return $this->hasOne(KolScreening::class)->latestOfMany('tanggal_listing');
    }
}
