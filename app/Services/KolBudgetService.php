<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\KolBudgetTransaction;
use App\Models\KolContent;
use App\Models\KolDeal;
use App\Models\KolMonthlyTarget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Ringkasan budget endorse bulanan di atas kol_deals yang sudah ada (Fase 2).
 * Spent = deal lunas; Committed = deal aktif belum lunas; Sisa = cap − keduanya.
 * Blended CPM paid menyambung ke views konten berlabel paid (Fase 1).
 */
class KolBudgetService
{
    public const KEY_BUDGET = 'kol_budget_monthly';

    public const KEY_ANCHOR = 'kol_cpm_anchor';

    /** @return array{budget:int,anchor:int,spent:int,committed:int,extras:int,sisa:int,cpm:?int,overAnchor:bool,topSharePct:int,shareLimitPct:int,overConcentration:bool,perCreator:Collection} */
    public function summary(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        // "Deal bulan ini" = periode_mulai dalam bulan (fallback created_at bila kosong).
        $deals = KolDeal::with('kol')->where('status', '!=', 'batal')->get()
            ->filter(fn ($d) => ($d->periode_mulai ?? $d->created_at)->between($start, $end));

        $dealSpent = (int) $deals->where('status_bayar', 'lunas')->sum('total_biaya');
        $committed = (int) $deals->where('status_bayar', '!=', 'lunas')->sum('total_biaya');
        // Pengeluaran tambahan (boost/hadiah/dll) bulan ini → ikut spent, TIDAK ke CPM.
        $extras = (int) KolBudgetTransaction::where('month', $month->format('Y-m'))->sum('amount');
        $spent = $dealSpent + $extras;
        // Override target per-bulan menang atas setelan global (bila diisi).
        $budget = KolMonthlyTarget::forMonth($month)?->budget ?? (int) AppSetting::get(self::KEY_BUDGET, '0');
        $anchor = (int) AppSetting::get(self::KEY_ANCHOR, '5000');
        $shareLimit = (float) AppSetting::get('kol_share_limit', '0.4');

        // CPM paid = biaya deal (bukan extras) ÷ (views konten paid bulan ini ÷ 1.000).
        $paidViews = (int) KolContent::where('label', 'paid')
            ->whereBetween('posted_at', [$start, $end])->with('latestSnapshot')->get()
            ->sum(fn ($c) => (int) ($c->latestSnapshot->views ?? 0));
        $paidCost = $dealSpent + $committed;
        $cpm = $paidViews > 0 ? (int) round($paidCost / ($paidViews / 1000)) : null;

        // Rincian per-creator (biaya deal per KOL) + share terhadap budget.
        $perCreator = $deals->groupBy('kol_id')->map(function ($group) use ($budget) {
            $cost = (int) $group->sum('total_biaya');

            return [
                'kol_id' => $group->first()->kol_id,
                'name' => '@'.($group->first()->kol->tiktok_username ?? '?'),
                'deals' => $group->count(),
                'cost' => $cost,
                'sharePct' => $budget > 0 ? (int) round($cost / $budget * 100) : 0,
            ];
        })->sortByDesc('cost')->values();
        $topShare = $budget > 0 && $perCreator->isNotEmpty() ? $perCreator->max('cost') / $budget : 0.0;

        return [
            'budget' => $budget,
            'anchor' => $anchor,
            'spent' => $spent,
            'committed' => $committed,
            'extras' => $extras,
            'sisa' => $budget - $spent - $committed,
            'cpm' => $cpm,
            'overAnchor' => $cpm !== null && $cpm > $anchor,
            'topSharePct' => (int) round($topShare * 100),
            'shareLimitPct' => (int) round($shareLimit * 100),
            'overConcentration' => $topShare > $shareLimit,
            'perCreator' => $perCreator,
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
