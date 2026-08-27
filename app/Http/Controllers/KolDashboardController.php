<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\KolContent;
use App\Models\KolPipelineCard;
use App\Services\KolAffiliateService;
use App\Services\KolBudgetService;
use App\Services\KolScoringService;
use Illuminate\Http\Request;

/**
 * Dashboard KOL (ringkasan 1-layar) — merangkai data pipeline, konten/views,
 * budget, dan affiliate/GMV+APS dari service yang sudah ada. Bagian uang
 * (budget/affiliate) hanya tampil bila punya izinnya.
 */
class KolDashboardController extends Controller
{
    public function index(Request $request, KolBudgetService $budget, KolAffiliateService $aff, KolScoringService $scoring)
    {
        $u = $request->user();
        $today = now()->startOfDay();
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        // Pipeline
        $cards = KolPipelineCard::active()->get();
        $pipeline = [
            'active' => $cards->count(),
            'terlambat' => $cards->filter(fn ($c) => $c->next_action_at?->lt($today))->count(),
            'hariIni' => $cards->filter(fn ($c) => $c->next_action_at?->isSameDay($today))->count(),
            'tanpaAksi' => $cards->filter(fn ($c) => ! $c->next_action_at)->count(),
        ];

        // Konten & views bulan ini
        $contents = KolContent::whereBetween('posted_at', [$monthStart, $monthEnd])->with(['kol', 'latestSnapshot'])->get();
        $views = fn ($c) => (int) ($c->latestSnapshot->views ?? 0);
        $totalViews = $contents->sum($views);
        $paidViews = $contents->where('label', 'paid')->sum($views);
        $target = (int) AppSetting::get('kol_views_target', '1000000');
        $proj = (int) round($totalViews * (now()->daysInMonth / max(1, now()->day)));
        $topContent = $contents->sortByDesc($views)->take(5)->values();

        // Budget (finance)
        $bud = $u->canDo('kol.deal.finance') ? $budget->summary(now()) : null;

        // Affiliate + APS (affiliate.view)
        $affData = null;
        if ($u->canDo('kol.affiliate.view')) {
            $ranking = $aff->monthly(now());
            $top = $ranking->take(5)->map(fn ($r) => [
                'kol' => $r->kol,
                'gmv' => (int) $r->gmv,
                'aps' => $scoring->aps($aff->apsInput((int) $r->kol_id, now())),
            ]);
            // Distribusi label APS (dari top yang dihitung) — indikasi cepat.
            $dist = $top->countBy(fn ($t) => $t['aps']['status'] === 'scored' ? $t['aps']['label'] : 'new');
            $affData = [
                'gmv' => (int) $ranking->sum('gmv'),
                'orders' => (int) $ranking->sum('orders'),
                'affiliates' => $ranking->count(),
                'top' => $top,
                'dist' => $dist,
            ];
        }

        return view('kols.dashboard', [
            'pipeline' => $pipeline,
            'totalViews' => $totalViews,
            'paidViews' => $paidViews,
            'earnedViews' => $totalViews - $paidViews,
            'target' => $target,
            'proj' => $proj,
            'viewsAman' => $target > 0 && $proj >= 0.95 * $target,
            'contentCount' => $contents->count(),
            'topContent' => $topContent,
            'budget' => $bud,
            'aff' => $affData,
            'apsLabels' => KolScoringService::APS_LABEL,
        ]);
    }
}
