<?php

namespace App\Services;

use App\Support\KolMetrics;

/**
 * APS (Affiliate Potential Score) + KSS (KOL Selection Score) — port PERSIS dari
 * app Iyuro (src/lib/scoring.ts). Threshold & bobot tidak boleh diubah tanpa
 * kalibrasi ulang. Skor 0–100; tiap komponen mengembalikan poin+bobot untuk
 * breakdown yang bisa dibantah user.
 */
class KolScoringService
{
    // Label pilihan KSS (buat form + tampilan).
    public const NICHE = ['beauty_majority' => 100, 'lifestyle_some' => 60, 'general' => 30, 'irrelevant' => 0];

    public const HISTORY = ['good' => 100, 'medium' => 60, 'none' => 50, 'bad' => 10];

    public const READINESS = ['active' => 100, 'rare' => 60, 'none' => 20];

    public const NICHE_LABEL = ['beauty_majority' => '≥ 50% konten skincare/beauty', 'lifestyle_some' => 'Lifestyle + sesekali beauty', 'general' => 'General', 'irrelevant' => 'Tidak relevan'];

    public const HISTORY_LABEL = ['good' => 'Pernah kolab, hasil bagus', 'medium' => 'Pernah kolab, hasil sedang', 'none' => 'Belum pernah kolab', 'bad' => 'Pernah kolab, hasil buruk/ghosting'];

    public const READINESS_LABEL = ['active' => 'Aktif affiliate + keranjang kuning rutin', 'rare' => 'Punya keranjang kuning tapi jarang', 'none' => 'Tidak ada keranjang kuning'];

    public const APS_LABEL = ['bina_intensif' => 'Bina intensif', 'pantau' => 'Pantau & dorong', 'nurture' => 'Nurture pasif', 'new' => 'New — belum cukup data'];

    public const KSS_LABEL = ['shortlist' => 'Shortlist', 'nego' => 'Nego dulu', 'tolak' => 'Tolak sopan, simpan'];

    /**
     * @param  array{weeklyGmv:array<int>,weeklyContent:array<int>,weeksOfData:int,monthGmv:int,monthViews:?int}  $in
     * @return array{status:string,score:?float,label:string,capped:bool,components:array}
     */
    public function aps(array $in): array
    {
        if ($in['weeksOfData'] < 4) {
            return ['status' => 'new', 'score' => null, 'label' => 'new', 'capped' => false, 'components' => []];
        }

        $g = KolMetrics::growthVelocity($in['weeklyGmv']) ?? 0;
        $gPts = match (true) {
            $g <= 0 => 0, $g <= 10 => 40, $g <= 25 => 60, $g <= 50 => 80, default => 100,
        };

        $cons = KolMetrics::consistency($in['weeklyContent']);
        $consPts = match (true) {
            $cons['hasTwoWeekGap'] => 0,
            $cons['avgPerWeek'] >= 3 && $cons['activeWeeks'] === count($in['weeklyContent']) => 100,
            $cons['avgPerWeek'] >= 1 => 60,
            default => 20,
        };

        $sPts = match (true) {
            $in['monthGmv'] < 500_000 => 20, $in['monthGmv'] < 2_000_000 => 50, $in['monthGmv'] < 5_000_000 => 75, default => 100,
        };

        $r = $in['monthViews'] ? KolMetrics::rpm($in['monthGmv'], $in['monthViews']) : null;
        $rPts = $r === null ? null : match (true) {
            $r < 5_000 => 0, $r < 20_000 => 40, $r < 60_000 => 70, $r < 150_000 => 90, default => 100,
        };

        $components = [
            ['key' => 'growth', 'label' => 'Growth velocity GMV', 'weight' => 0.35, 'points' => $gPts, 'raw' => round($g, 1).'% WoW'],
            ['key' => 'rpm', 'label' => 'Efisiensi konversi (RPM)', 'weight' => 0.25, 'points' => $rPts ?? 0, 'raw' => $r === null ? 'n/a' : 'Rp '.number_format((int) $r, 0, ',', '.')],
            ['key' => 'consistency', 'label' => 'Konsistensi posting', 'weight' => 0.2, 'points' => $consPts, 'raw' => round($cons['avgPerWeek'], 1).' konten/mgg'],
            ['key' => 'scale', 'label' => 'Skala GMV bulan ini', 'weight' => 0.2, 'points' => $sPts, 'raw' => 'Rp '.number_format($in['monthGmv'], 0, ',', '.')],
        ];

        // RPM null → bobotnya dialihkan ke komponen lain (reweight).
        $usable = $rPts === null ? array_values(array_filter($components, fn ($c) => $c['key'] !== 'rpm')) : $components;
        $totalWeight = array_sum(array_column($usable, 'weight'));
        $score = 0.0;
        foreach ($usable as $c) {
            $score += ($c['weight'] / $totalWeight) * $c['points'];
        }

        $lastTwo = array_slice($in['weeklyContent'], -2);
        $noPost = count($lastTwo) === 2 && (int) $lastTwo[0] === 0 && (int) $lastTwo[1] === 0;
        $capped = false;
        if ($noPost && $score > 40) {
            $score = 40;
            $capped = true;
        }
        $score = round($score * 10) / 10;
        $label = $score >= 75 ? 'bina_intensif' : ($score >= 50 ? 'pantau' : 'nurture');

        return ['status' => 'scored', 'score' => $score, 'label' => $label, 'capped' => $capped, 'components' => $components];
    }

    /**
     * @param  array{rate:int,barterOnly:bool,medianViews:int,engagementRate:float,niche:string,history:string,readiness:string}  $in
     * @return array{score:float,decision:string,ecpm:?float,components:array,advice:string}
     */
    public function kss(array $in): array
    {
        $e = $in['barterOnly'] ? null : KolMetrics::ecpm($in['rate'], $in['medianViews']);
        $ePts = $in['barterOnly'] ? 90 : ($e === null ? 0 : match (true) {
            $e <= 2_500 => 100, $e <= 5_000 => 80, $e <= 10_000 => 55, $e <= 20_000 => 30, default => 0,
        });
        $erPts = match (true) {
            $in['engagementRate'] > 8 => 100, $in['engagementRate'] >= 5 => 80, $in['engagementRate'] >= 3 => 55, $in['engagementRate'] >= 1.5 => 30, default => 0,
        };

        $components = [
            ['key' => 'ecpm', 'label' => 'Efisiensi biaya (eCPM)', 'weight' => 0.35, 'points' => $ePts, 'raw' => $in['barterOnly'] ? 'Barter' : ($e === null ? 'n/a' : 'Rp '.number_format((int) $e, 0, ',', '.'))],
            ['key' => 'er', 'label' => 'Engagement rate', 'weight' => 0.2, 'points' => $erPts, 'raw' => number_format($in['engagementRate'], 1, ',', '.').'%'],
            ['key' => 'niche', 'label' => 'Relevansi niche beauty', 'weight' => 0.2, 'points' => self::NICHE[$in['niche']] ?? 0, 'raw' => self::NICHE_LABEL[$in['niche']] ?? '—'],
            ['key' => 'history', 'label' => 'Riwayat dengan brand', 'weight' => 0.15, 'points' => self::HISTORY[$in['history']] ?? 0, 'raw' => self::HISTORY_LABEL[$in['history']] ?? '—'],
            ['key' => 'readiness', 'label' => 'Kesiapan komersial', 'weight' => 0.1, 'points' => self::READINESS[$in['readiness']] ?? 0, 'raw' => self::READINESS_LABEL[$in['readiness']] ?? '—'],
        ];

        $score = 0.0;
        foreach ($components as $c) {
            $score += $c['weight'] * $c['points'];
        }
        $score = round($score * 10) / 10;
        $decision = $score >= 70 ? 'shortlist' : ($score >= 50 ? 'nego' : 'tolak');

        $advice = match ($decision) {
            'shortlist' => 'Masuk shortlist bulan ini. Cek sisa budget & aturan maks 40% per creator.',
            'nego' => $in['medianViews'] > 0
                ? 'Tawarkan barter + komisi, atau minta rate turun ke ≤ Rp '.number_format((int) floor(5000 * $in['medianViews'] / 1000), 0, ',', '.').' agar eCPM ≤ Rp 5.000.'
                : 'Tawarkan barter + komisi affiliate, atau minta rate turun sampai eCPM ≤ Rp 5.000.',
            default => 'Tolak dengan sopan dan simpan di database — follower & konten bisa berubah dalam 6 bulan.',
        };

        return ['score' => $score, 'decision' => $decision, 'ecpm' => $e, 'components' => $components, 'advice' => $advice];
    }
}
