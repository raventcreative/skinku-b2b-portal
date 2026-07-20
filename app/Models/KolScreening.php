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

    public const VERDICT_MASIH = '🟡 Masih Oke';

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
     * Indikator MEDIAN — 3 tingkat, rumus kolom V sheet Listing KOL:
     * < 60rb Worth It · < 120rb Masih Oke · sisanya Kemahalan.
     *
     * CPM null (views nol semua) tetap Kemahalan: bayar ratecard untuk nol views
     * jelas bukan Worth It, dan verdict kosong cuma memindahkan keputusan ke
     * pembaca.
     */
    public function getVerdictMedianAttribute(): string
    {
        if ($this->cpm_median === null) {
            return self::VERDICT_MAHAL;
        }

        return match (true) {
            $this->cpm_median < config('kol.median_worth') => self::VERDICT_WORTH,
            $this->cpm_median < config('kol.median_masih_oke') => self::VERDICT_MASIH,
            default => self::VERDICT_MAHAL,
        };
    }

    /**
     * Indikator MEAN — 5 tingkat, rumus TERBARU kolom U sheet Listing KOL
     * (baris awal sheet masih memakai rumus lama 3 tingkat; versi 5 tingkat
     * yang dipakai). Skala penuh di config/kol.php.
     */
    public function getVerdictRataAttribute(): string
    {
        if ($this->cpm_rata === null) {
            return config('kol.mean_tier_terburuk');
        }

        foreach (config('kol.mean_tiers') as [$batas, $label]) {
            if ($this->cpm_rata < $batas) {
                return $label;
            }
        }

        return config('kol.mean_tier_terburuk');
    }

    /** Ratio versi mean (kolom U: Q/F) — pendamping ratio median yang sudah ada. */
    public function getRatioRataAttribute(): ?float
    {
        $followers = (int) ($this->kol?->followers ?? 0);

        return $followers > 0 ? round($this->rata_views / $followers * 100, 2) : null;
    }

    /*
     * GMV + Viral + Fake Detector — porting rumus Excel kolom W (rumusnya
     * terbaca utuh di formula bar, konstanta di config/kol.php).
     */

    /** Estimasi GMV: median × konversi × nilai order rata-rata. */
    public function getGmvEstimateAttribute(): int
    {
        return (int) round($this->median_views * config('kol.gmv_conversion') * config('kol.gmv_avg_order'));
    }

    /**
     * High = mean jauh di atas median (ada video meledak, bukan performa stabil).
     * Median 0 → Low: rumus Excel-nya menghasilkan "High" untuk views nol semua
     * (0 ≥ 0×2), yang jelas bukan maksudnya — deviasi kecil yang disengaja.
     */
    public function getViralLabelAttribute(): string
    {
        if ($this->median_views <= 0) {
            return 'Low';
        }

        return match (true) {
            $this->rata_views >= $this->median_views * config('kol.viral_high') => 'High',
            $this->rata_views >= $this->median_views * config('kol.viral_mid') => 'Mid',
            default => 'Low',
        };
    }

    /**
     * Deteksi followers palsu: median views terlalu kecil dibanding followers.
     * Null bila followers 0 — tak ada pembanding, bukan berarti aman.
     */
    public function getFakeLabelAttribute(): ?string
    {
        $followers = (int) ($this->kol?->followers ?? 0);
        if ($followers <= 0) {
            return null;
        }

        return match (true) {
            $this->median_views < $followers * config('kol.fake_red') => '●Red',
            $this->median_views < $followers * config('kol.fake_watch') => '◯Watch',
            default => '🟢Safe',
        };
    }
}
