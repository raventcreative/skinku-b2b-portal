<?php

namespace App\Http\Controllers;

use App\Models\ContentPostSnapshot;
use App\Models\ContentPostTarget;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Insight konten brand (FR-82, FR-83): ringkasan performa postingan yang terbit
 * dalam periode, grafik views, top postingan & laporan per creator.
 * Pola tampilan mengikuti KOL Konten & Views.
 *
 * ponytail: agregasi di collection PHP — cukup untuk ratusan postingan/periode;
 * pindah ke query agregat SQL bila sudah ribuan.
 */
class ContentInsightController extends Controller
{
    public const PERIODS = [7, 30, 90];

    public function index(Request $request): View
    {
        $days = in_array((int) $request->query('days'), self::PERIODS, true) ? (int) $request->query('days') : 30;
        $since = now()->subDays($days)->startOfDay();

        $targets = ContentPostTarget::with(['post.user', 'latestSnapshot'])
            ->where('status', ContentPostTarget::PUBLISHED)
            ->where('published_at', '>=', $since)
            ->get();

        $snaps = $targets->pluck('latestSnapshot')->filter();
        $views = (int) $snaps->sum('views');
        $interactions = $snaps->sum(fn (ContentPostSnapshot $s) => $s->interactions());

        $daily = ContentPostSnapshot::whereIn('content_post_target_id', $targets->pluck('id'))
            ->where('captured_on', '>=', $since)
            ->selectRaw('captured_on, sum(views) as views')->groupBy('captured_on')->orderBy('captured_on')->get();

        $creators = $targets->groupBy(fn ($t) => $t->post->user_id)->map(function ($group) {
            $s = $group->pluck('latestSnapshot')->filter();
            $v = (int) $s->sum('views');

            return [
                'name' => $group->first()->post->user?->fullname ?? $group->first()->post->user?->name ?? '—',
                'posts' => $group->pluck('content_post_id')->unique()->count(),
                'targets' => $group->count(),
                'views' => $v,
                'er' => $v ? $s->sum(fn ($x) => $x->interactions()) / $v * 100 : null,
            ];
        })->sortByDesc('views')->values();

        return view('content.insights', [
            'days' => $days,
            'stats' => [
                'views' => $views,
                'er' => $views ? $interactions / $views * 100 : null,
                'posts' => $targets->pluck('content_post_id')->unique()->count(),
                'interactions' => $interactions,
                'synced' => $snaps->count(),
                'targets' => $targets->count(),
            ],
            'chart' => [
                'labels' => $daily->map(fn ($d) => Carbon::parse($d->captured_on)->format('d M'))->all(),
                'views' => $daily->pluck('views')->map(fn ($v) => (int) $v)->all(),
            ],
            'top' => $targets->filter(fn ($t) => $t->latestSnapshot)->sortByDesc(fn ($t) => (int) $t->latestSnapshot->views)->take(10)->values(),
            'creators' => $creators,
        ]);
    }
}
