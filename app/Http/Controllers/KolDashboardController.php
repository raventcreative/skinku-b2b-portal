<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\KolAffiliateTransaction;
use App\Models\KolContent;
use App\Models\KolMonthlyTarget;
use App\Models\KolPipelineCard;
use App\Services\KolAffiliateService;
use App\Services\KolBudgetService;
use App\Services\KolScoringService;
use App\Support\KolMetrics;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
        $m = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('bulan'))
            ? Carbon::createFromFormat('Y-m', (string) $request->query('bulan'))->startOfMonth()
            : now()->startOfMonth();
        $isCurrent = $m->isSameMonth(now());
        $today = now()->startOfDay();
        $monthStart = $m->copy()->startOfMonth();
        $monthEnd = $m->copy()->endOfMonth();

        // Pipeline
        $cards = KolPipelineCard::active()->with('kol')->get();
        $pipeline = [
            'active' => $cards->count(),
            'terlambat' => $cards->filter(fn ($c) => $c->next_action_at?->lt($today))->count(),
            'hariIni' => $cards->filter(fn ($c) => $c->next_action_at?->isSameDay($today))->count(),
            'tanpaAksi' => $cards->filter(fn ($c) => ! $c->next_action_at)->count(),
        ];
        // Daftar pengingat: aksi terdekat (punya tenggat), urut tenggat, maks 8 baris.
        $reminders = $cards->filter(fn ($c) => $c->next_action_at)->sortBy('next_action_at')->take(8)->values();

        // Konten & views bulan ini
        $contents = KolContent::whereBetween('posted_at', [$monthStart, $monthEnd])->with(['kol', 'latestSnapshot'])->get();
        $views = fn ($c) => (int) ($c->latestSnapshot->views ?? 0);
        $totalViews = $contents->sum($views);
        $paidViews = $contents->where('label', 'paid')->sum($views);
        // Override target per-bulan menang atas setelan global (bila diisi).
        $ov = KolMonthlyTarget::forMonth($m);
        $target = $ov?->views_target ?? (int) AppSetting::get('kol_views_target', '1000000');
        $gmvTarget = $ov?->gmv_target ?? (int) AppSetting::get('kol_gmv_target', '0');
        $proj = $isCurrent ? (int) round($totalViews * ($m->daysInMonth / max(1, now()->day))) : $totalViews;
        $topContent = $contents->sortByDesc($views)->take(5)->values();

        // Budget (finance)
        $bud = $u->canDo('kol.deal.finance') ? $budget->summary($m) : null;

        // Affiliate + APS (affiliate.view)
        $affData = null;
        if ($u->canDo('kol.affiliate.view')) {
            $ranking = $aff->monthly($m);
            $top = $ranking->take(5)->map(fn ($r) => [
                'kol' => $r->kol->loadMissing('tiktokProfile'), // demografi TikTok (bila sudah disimpan)
                'gmv' => (int) $r->gmv,
                'aps' => $scoring->aps($aff->apsInput((int) $r->kol_id, $m)),
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

        // Grafik: views kumulatif (paid/earned) per hari + garis target linear.
        $daysInMonth = $m->daysInMonth;
        $todayD = $isCurrent ? now()->day : $daysInMonth;
        $byDay = $contents->groupBy(fn ($c) => $c->posted_at->day);
        $cumPaid = $cumEarned = $targetLine = [];
        $rP = $rE = 0;
        for ($d = 1; $d <= $daysInMonth; $d++) {
            if ($d <= $todayD) {
                foreach ($byDay->get($d, collect()) as $c) {
                    $vv = (int) ($c->latestSnapshot->views ?? 0);
                    $c->label === 'paid' ? $rP += $vv : $rE += $vv;
                }
                $cumPaid[] = $rP;
                $cumEarned[] = $rE;
            } else {
                $cumPaid[] = null;
                $cumEarned[] = null;
            }
            $targetLine[] = (int) round($target * $d / $daysInMonth);
        }

        // Grafik GMV mingguan (affiliate.view).
        $gmvWeeks = $gmvWeekLabels = [];
        if ($u->canDo('kol.affiliate.view')) {
            $cur = $m->copy()->startOfMonth()->startOfWeek();
            while ($cur <= $monthEnd) {
                $we = $cur->copy()->endOfWeek();
                $gmvWeeks[] = (int) KolAffiliateTransaction::notCancelled()->whereBetween('order_date', [$cur, $we])->sum('gmv');
                $gmvWeekLabels[] = $cur->format('d M');
                $cur = $cur->copy()->addWeek();
            }
        }

        // ROAS + ROI margin-aware (butuh biaya deal & GMV).
        $margin = $ov?->margin ?? (float) AppSetting::get('kol_gross_margin', '0.3');
        $dealCost = $bud ? (int) ($bud['spent'] + $bud['committed']) : 0;
        $gmv = (int) ($affData['gmv'] ?? 0);
        $roas = KolMetrics::roas($gmv, $dealCost);
        $roi = KolMetrics::roiMarginAware($gmv, $margin, $dealCost);
        $isEmpty = $pipeline['active'] === 0 && $contents->isEmpty() && $gmv === 0;

        return view('kols.dashboard', [
            'month' => $m->format('Y-m'),
            'isCurrent' => $isCurrent,
            'prevMonth' => $m->copy()->subMonth()->format('Y-m'),
            'nextMonth' => $m->copy()->addMonth()->format('Y-m'),
            'roas' => $roas, 'roi' => $roi, 'margin' => $margin, 'isEmpty' => $isEmpty,
            'chart' => [
                'days' => range(1, $daysInMonth),
                'cumPaid' => $cumPaid, 'cumEarned' => $cumEarned, 'target' => $targetLine,
                'gmvWeeks' => $gmvWeeks, 'gmvWeekLabels' => $gmvWeekLabels,
            ],
            'pipeline' => $pipeline,
            'reminders' => $reminders,
            'gmvTarget' => $gmvTarget,
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
