<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\KolContent;
use App\Models\KolMonthlyTarget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Ringkasan Konten & Views KOL sebulan — satu sumber untuk halaman KOL → Konten & Views dan alat AI
 * konten_views_kol, jadi angkanya selalu sama. Views = snapshot terbaru tiap konten yang diposting di bulan itu.
 */
class KolKontenService
{
    /** Target views global (AppSetting); override per-bulan (KolMonthlyTarget) menang bila diisi. */
    public const TARGET_KEY = 'kol_views_target';

    /**
     * @param  array{creator?:mixed,platform?:mixed,label?:mixed,type?:mixed}  $filters  sama dgn filter halaman
     * @return array{contents:Collection<int,KolContent>,total:int,paid:int,earned:int,target:int,proj:int,isCurrent:bool,aman:bool,daysLeft:int,perDayNeeded:int}
     */
    public function bulan(Carbon $start, array $filters = []): array
    {
        $start = $start->copy()->startOfMonth();
        $contents = KolContent::with(['kol', 'deal', 'latestSnapshot'])
            ->whereBetween('posted_at', [$start, $start->copy()->endOfMonth()])
            ->when($filters['creator'] ?? null, fn ($q, $v) => $q->where('kol_id', $v))
            ->when($filters['platform'] ?? null, fn ($q, $v) => $q->where('platform', $v))
            ->when($filters['label'] ?? null, fn ($q, $v) => $q->where('label', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('content_type', $v))
            ->orderByDesc('posted_at')->get();

        $views = fn ($c) => (int) ($c->latestSnapshot->views ?? 0);
        $total = (int) $contents->sum($views);
        $paid = (int) $contents->where('label', 'paid')->sum($views);
        $target = KolMonthlyTarget::forMonth($start)?->views_target ?? (int) AppSetting::get(self::TARGET_KEY, '1000000');
        $isCurrent = $start->isSameMonth(now());
        $proj = $isCurrent ? (int) round($total * ($start->daysInMonth / max(1, now()->day))) : $total;

        // Kebutuhan views/hari untuk kejar target (bulan berjalan).
        $daysLeft = $isCurrent ? max(1, $start->daysInMonth - now()->day + 1) : 0;

        return [
            'contents' => $contents,
            'total' => $total,
            'paid' => $paid,
            'earned' => $total - $paid,
            'target' => $target,
            'proj' => $proj,
            'isCurrent' => $isCurrent,
            'aman' => $target > 0 && $proj >= 0.95 * $target,
            'daysLeft' => $daysLeft,
            'perDayNeeded' => ($isCurrent && $target > $total) ? (int) ceil(($target - $total) / $daysLeft) : 0,
        ];
    }
}
