<?php

namespace App\Http\Controllers;

use App\Models\Kol;
use App\Models\KolScore;
use App\Services\KolAffiliateService;
use App\Services\KolScoreService;
use App\Services\KolScoringService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Skor KOL — 2 tab (mengikuti app Iyuro): Ranking APS (affiliate 4-mingguan) +
 * Kalkulator KSS (seleksi KOL baru, form murni; median auto-isi dari screening).
 */
class KolScoringController extends Controller
{
    public function kss(Request $request, KolScoringService $svc, KolAffiliateService $aff, KolScoreService $scores)
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

            // Persist skor KSS ke jejak historis bila dihitung untuk KOL tertentu.
            if (! empty($input['kol_id'])) {
                $scores->record((int) $input['kol_id'], KolScore::TYPE_KSS, $result['score'], $result['decision'],
                    ['ecpm' => $result['ecpm'], 'rate' => (int) $input['rate'], 'median_views' => (int) $input['median_views']],
                    $request->user()->id);
            }
        }

        // Tab Ranking APS — hanya untuk pemegang izin Affiliate (butuh data GMV).
        $canAffiliate = $request->user()->canDo('kol.affiliate.view');
        $apsScored = collect();
        $apsNew = collect();
        $kssHistory = collect();
        if ($canAffiliate) {
            $ranking = $aff->monthly(now())->map(function ($r) use ($svc, $aff) {
                $in = $aff->apsInput((int) $r->kol_id, now());

                return ['kol' => $r->kol, 'gmv' => (int) $r->gmv, 'aps' => $svc->aps($in), 'weeks' => $in['weeksOfData']];
            });
            // Rekam jejak APS harian (idempoten) dari ranking yang sudah dihitung.
            $scores->snapshotAps($ranking, $request->user()->id);
            // Yang cukup data → ranking urut SKOR (bukan GMV); yang <4 minggu → tabel "New".
            $apsScored = $ranking->filter(fn ($r) => $r['aps']['status'] === 'scored')
                ->sortByDesc(fn ($r) => $r['aps']['score'])->values();
            $apsNew = $ranking->filter(fn ($r) => $r['aps']['status'] === 'new')->values();
            $kssHistory = KolScore::where('type', 'kss')->with('kol')->latest('id')->limit(20)->get();
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
            'apsScored' => $apsScored,
            'apsNew' => $apsNew,
            'kssHistory' => $kssHistory,
            'apsLabels' => KolScoringService::APS_LABEL,
            // Default tab: KSS bila baru submit form; selain itu Ranking APS (bila ada).
            'tab' => $result ? 'kss' : ($canAffiliate ? 'aps' : 'kss'),
        ]);
    }

    /** Rekam snapshot APS semua affiliate ke jejak historis (tombol manual). */
    public function snapshotAps(Request $request, KolScoringService $svc, KolAffiliateService $aff, KolScoreService $scores): RedirectResponse
    {
        abort_unless($request->user()->canDo('kol.affiliate.view'), 403);

        $ranking = $aff->monthly(now())->map(fn ($r) => [
            'kol' => $r->kol, 'aps' => $svc->aps($aff->apsInput((int) $r->kol_id, now())),
        ]);
        $n = $scores->snapshotAps($ranking, $request->user()->id);

        return back()->with('status', "Snapshot APS direkam untuk {$n} creator.");
    }
}
