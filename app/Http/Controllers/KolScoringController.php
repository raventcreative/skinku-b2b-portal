<?php

namespace App\Http\Controllers;

use App\Models\Kol;
use App\Services\KolAffiliateService;
use App\Services\KolScoringService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Skor KOL — 2 tab (mengikuti app Iyuro): Ranking APS (affiliate 4-mingguan) +
 * Kalkulator KSS (seleksi KOL baru, form murni; median auto-isi dari screening).
 */
class KolScoringController extends Controller
{
    public function kss(Request $request, KolScoringService $svc, KolAffiliateService $aff)
    {
        $result = null;
        $input = null;

        if ($request->isMethod('post')) {
            $input = $request->validate([
                'kol_id' => ['nullable', 'integer', 'exists:kols,id'],
                'rate' => ['required', 'integer', 'min:0'],
                'barter' => ['nullable', 'boolean'],
                'median_views' => ['required', 'integer', 'min:0'],
                'engagement_rate' => ['required', 'numeric', 'min:0'],
                'niche' => ['required', Rule::in(array_keys(KolScoringService::NICHE))],
                'history' => ['required', Rule::in(array_keys(KolScoringService::HISTORY))],
                'readiness' => ['required', Rule::in(array_keys(KolScoringService::READINESS))],
            ]);

            $result = $svc->kss([
                'rate' => (int) $input['rate'],
                'barterOnly' => (bool) ($input['barter'] ?? false),
                'medianViews' => (int) $input['median_views'],
                'engagementRate' => (float) $input['engagement_rate'],
                'niche' => $input['niche'],
                'history' => $input['history'],
                'readiness' => $input['readiness'],
            ]);
        }

        // Tab Ranking APS — hanya untuk pemegang izin Affiliate (butuh data GMV).
        $canAffiliate = $request->user()->canDo('kol.affiliate.view');
        $apsRanking = collect();
        if ($canAffiliate) {
            $apsRanking = $aff->monthly(now())->map(fn ($r) => [
                'kol' => $r->kol,
                'gmv' => (int) $r->gmv,
                'aps' => $svc->aps($aff->apsInput((int) $r->kol_id, now())),
            ]);
        }

        return view('kols.skor.index', [
            'kols' => Kol::with('latestScreening')->orderBy('tiktok_username')->get(['id', 'tiktok_username', 'followers']),
            'result' => $result,
            'old' => $input,
            'nicheOpts' => KolScoringService::NICHE_LABEL,
            'historyOpts' => KolScoringService::HISTORY_LABEL,
            'readinessOpts' => KolScoringService::READINESS_LABEL,
            'decisionLabel' => KolScoringService::KSS_LABEL,
            'canAffiliate' => $canAffiliate,
            'apsRanking' => $apsRanking,
            'apsLabels' => KolScoringService::APS_LABEL,
            // Default tab: KSS bila baru submit form; selain itu Ranking APS (bila ada).
            'tab' => $result ? 'kss' : ($canAffiliate ? 'aps' : 'kss'),
        ]);
    }
}
