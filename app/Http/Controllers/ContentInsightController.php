<?php

namespace App\Http\Controllers;

use App\Services\KontenInsightService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Insight konten brand (FR-82, FR-83): ringkasan performa postingan yang terbit
 * dalam periode, grafik views, top postingan & laporan per creator — dihitung
 * KontenInsightService (dipakai juga alat AI insight_konten).
 * Pola tampilan mengikuti KOL Konten & Views.
 */
class ContentInsightController extends Controller
{
    public const PERIODS = KontenInsightService::PERIODS;

    public function index(Request $request, KontenInsightService $insight): View
    {
        $days = in_array((int) $request->query('days'), self::PERIODS, true) ? (int) $request->query('days') : 30;
        $platform = in_array($request->query('platform'), ['instagram', 'tiktok'], true) ? $request->query('platform') : 'semua';

        // syncErrors, stats, chart, top, creators.
        return view('content.insights', [
            'days' => $days,
            'platform' => $platform,
            'platformLabel' => $platform === 'semua' ? 'Semua platform' : ($platform === 'tiktok' ? 'TikTok' : 'Instagram'),
        ] + $insight->ringkasan($days, $platform));
    }
}
