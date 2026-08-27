<?php

namespace App\Http\Controllers;

use App\Models\Kol;
use App\Services\KolScoringService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * KSS — kalkulator seleksi KOL baru (form → skor + keputusan + advice). Kalkulator
 * murni (tak menyimpan). Median views auto-isi dari screening terbaru (JS).
 */
class KolScoringController extends Controller
{
    public function kss(Request $request, KolScoringService $svc)
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

        return view('kols.skor.kss', [
            'kols' => Kol::with('latestScreening')->orderBy('tiktok_username')->get(['id', 'tiktok_username', 'followers']),
            'result' => $result,
            'old' => $input,
            'nicheOpts' => KolScoringService::NICHE_LABEL,
            'historyOpts' => KolScoringService::HISTORY_LABEL,
            'readinessOpts' => KolScoringService::READINESS_LABEL,
            'decisionLabel' => KolScoringService::KSS_LABEL,
        ]);
    }
}
