<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\KolContent;
use App\Models\KolDeal;
use App\Models\KolMonthlyTarget;
use Illuminate\Support\Carbon;

/**
 * Ringkasan budget endorse bulanan di atas kol_deals yang sudah ada (Fase 2).
 * Spent = deal lunas; Committed = deal aktif belum lunas; Sisa = cap − keduanya.
 * Blended CPM paid menyambung ke views konten berlabel paid (Fase 1).
 */
class KolBudgetService
{
    public const KEY_BUDGET = 'kol_budget_monthly';

    public const KEY_ANCHOR = 'kol_cpm_anchor';

    /** @return array{budget:int,anchor:int,spent:int,committed:int,sisa:int,cpm:?int,overAnchor:bool,topSharePct:int,overConcentration:bool} */
    public function summary(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        // "Deal bulan ini" = periode_mulai dalam bulan (fallback created_at bila kosong).
        $deals = KolDeal::where('status', '!=', 'batal')->get()
            ->filter(fn ($d) => ($d->periode_mulai ?? $d->created_at)->between($start, $end));

        $spent = (int) $deals->where('status_bayar', 'lunas')->sum('total_biaya');
        $committed = (int) $deals->where('status_bayar', '!=', 'lunas')->sum('total_biaya');
        // Override target per-bulan menang atas setelan global (bila diisi).
        $budget = KolMonthlyTarget::forMonth($month)?->budget ?? (int) AppSetting::get(self::KEY_BUDGET, '0');
        $anchor = (int) AppSetting::get(self::KEY_ANCHOR, '5000');

        // CPM paid = total biaya deal ÷ (views konten paid bulan ini ÷ 1.000).
        $paidViews = (int) KolContent::where('label', 'paid')
            ->whereBetween('posted_at', [$start, $end])->with('latestSnapshot')->get()
            ->sum(fn ($c) => (int) ($c->latestSnapshot->views ?? 0));
        $paidCost = $spent + $committed;
        $cpm = $paidViews > 0 ? (int) round($paidCost / ($paidViews / 1000)) : null;

        // Konsentrasi: satu KOL menyerap > 40% budget?
        $perKol = $deals->groupBy('kol_id')->map->sum('total_biaya');
        $topShare = $budget > 0 && $perKol->isNotEmpty() ? $perKol->max() / $budget : 0.0;

        return [
            'budget' => $budget,
            'anchor' => $anchor,
            'spent' => $spent,
            'committed' => $committed,
            'sisa' => $budget - $spent - $committed,
            'cpm' => $cpm,
            'overAnchor' => $cpm !== null && $cpm > $anchor,
            'topSharePct' => (int) round($topShare * 100),
            'overConcentration' => $topShare > 0.4,
        ];
    }

    /** Deal yang tagihan belum lunas (buat reminder pembayaran) — urut tenggat terdekat. */
    public function unpaid()
    {
        return KolDeal::with('kol')
            ->whereIn('status', ['berjalan', 'selesai'])
            ->whereIn('status_bayar', ['belum', 'dp'])
            ->orderByRaw('periode_selesai is null, periode_selesai asc')
            ->get();
    }
}
