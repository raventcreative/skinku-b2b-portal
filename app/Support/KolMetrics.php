<?php

namespace App\Support;

/**
 * Rumus metrik KOL — port PERSIS dari app Iyuro (src/lib/metrics.ts). Fungsi
 * murni, null-safe (pembagi 0 → null). Dipakai KolScoringService (APS/KSS).
 */
class KolMetrics
{
    /** Biaya per 1.000 views. */
    public static function cpm(float $cost, float $views): ?float
    {
        return $views > 0 ? ($cost / $views) * 1000 : null;
    }

    /** Expected CPM: ratecard ÷ median views × 1.000. */
    public static function ecpm(float $rate, float $medianViews): ?float
    {
        return $medianViews > 0 ? ($rate / $medianViews) * 1000 : null;
    }

    /** GMV per 1.000 views. */
    public static function rpm(float $gmv, float $views): ?float
    {
        return $views > 0 ? ($gmv / $views) * 1000 : null;
    }

    public static function roas(float $gmv, float $cost): ?float
    {
        return $cost > 0 ? $gmv / $cost : null;
    }

    /** ROI margin-aware = (laba kotor GMV − biaya) ÷ biaya. margin = 0..1. */
    public static function roiMarginAware(float $gmv, float $margin, float $cost): ?float
    {
        return $cost > 0 ? round(($gmv * $margin - $cost) / $cost, 2) : null;
    }

    /** Rata-rata pertumbuhan WoW (%) dari deret GMV mingguan (lama→baru). 0→positif = +100%. */
    public static function growthVelocity(array $weekly): ?float
    {
        $weekly = array_values($weekly);
        if (count($weekly) < 2) {
            return null;
        }
        $rates = [];
        for ($i = 1; $i < count($weekly); $i++) {
            $prev = $weekly[$i - 1];
            $cur = $weekly[$i];
            $rates[] = $prev <= 0 ? ($cur > 0 ? 100 : 0) : (($cur - $prev) / $prev) * 100;
        }

        return array_sum($rates) / count($rates);
    }

    /** @return array{activeWeeks:int,avgPerWeek:float,hasTwoWeekGap:bool} */
    public static function consistency(array $weeklyContentCount): array
    {
        $weeks = count($weeklyContentCount) ?: 1;
        $active = count(array_filter($weeklyContentCount, fn ($c) => $c > 0));
        $avg = array_sum($weeklyContentCount) / $weeks;
        $gap = 0;
        $twoGap = false;
        foreach ($weeklyContentCount as $c) {
            $gap = $c > 0 ? 0 : $gap + 1;
            if ($gap >= 2) {
                $twoGap = true;
            }
        }

        return ['activeWeeks' => $active, 'avgPerWeek' => $avg, 'hasTwoWeekGap' => $twoGap];
    }

    public static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /** Run-rate: nilai MTD ÷ hari berjalan × hari dalam bulan. @return array{projected:float,pctOfTarget:float,status:string} */
    public static function pace(float $mtd, int $day, int $daysInMonth, float $target): array
    {
        $day = max(1, $day);
        $projected = ($mtd / $day) * $daysInMonth;
        $pct = $target > 0 ? ($projected / $target) * 100 : 0;
        $status = $pct >= 95 ? 'on_track' : ($pct >= 70 ? 'at_risk' : 'behind');

        return ['projected' => $projected, 'pctOfTarget' => $pct, 'status' => $status];
    }
}
