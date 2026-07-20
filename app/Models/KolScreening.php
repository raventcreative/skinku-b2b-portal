<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Semua angka turunan di sini accessor, BUKAN kolom: kalau disimpan, mengubah
 * ambang CPM di config membuat verdict lama basi diam-diam.
 */
class KolScreening extends Model
{
    public const VERDICT_WORTH = '🟢 Worth It';

    public const VERDICT_MAHAL = '🔴 Kemahalan';

    protected $fillable = [
        'kol_id', 'tanggal_listing', 'ratecard',
        'views_1', 'views_2', 'views_3', 'views_4', 'views_5', 'views_6', 'views_7',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['tanggal_listing' => 'date', 'ratecard' => 'integer'];
    }

    public function kol()
    {
        return $this->belongsTo(Kol::class);
    }

    /** @return array<int, int> views 7 video, urutan input */
    public function views(): array
    {
        return array_map(fn (int $i) => (int) $this->{"views_{$i}"}, range(1, 7));
    }

    public function getTotalViewsAttribute(): int
    {
        return array_sum($this->views());
    }

    public function getRataViewsAttribute(): float
    {
        return round($this->total_views / 7, 1);
    }

    /** 7 nilai → nilai tengah setelah diurutkan (angka 0 IKUT diurutkan, tidak dibuang). */
    public function getMedianViewsAttribute(): int
    {
        $v = $this->views();
        sort($v);

        return $v[3];
    }

    /** Rupiah per 1000 views basis median. Null bila median 0 — CPM tak terdefinisi. */
    public function getCpmMedianAttribute(): ?float
    {
        return $this->median_views > 0 ? round($this->ratecard / $this->median_views * 1000, 0) : null;
    }

    public function getCpmRataAttribute(): ?float
    {
        return $this->rata_views > 0 ? round($this->ratecard / $this->rata_views * 1000, 0) : null;
    }

    /** median ÷ followers KOL, dalam persen. Null bila followers 0. */
    public function getRatioAttribute(): ?float
    {
        $followers = (int) ($this->kol?->followers ?? 0);

        return $followers > 0 ? round($this->median_views / $followers * 100, 2) : null;
    }

    /**
     * CPM null (views nol semua) tetap Kemahalan: bayar ratecard untuk nol views
     * jelas bukan Worth It, dan verdict kosong cuma memindahkan keputusan ke
     * pembaca.
     */
    public function getVerdictMedianAttribute(): string
    {
        return $this->cpm_median !== null && $this->cpm_median <= config('kol.cpm_threshold')
            ? self::VERDICT_WORTH
            : self::VERDICT_MAHAL;
    }

    public function getVerdictRataAttribute(): string
    {
        return $this->cpm_rata !== null && $this->cpm_rata <= config('kol.cpm_threshold')
            ? self::VERDICT_WORTH
            : self::VERDICT_MAHAL;
    }
}
