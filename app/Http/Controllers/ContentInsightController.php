<?php

namespace App\Http\Controllers;

use App\Models\ContentPostSnapshot;
use App\Models\ContentPostTarget;
use App\Models\SocialConnection;
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
        $platform = in_array($request->query('platform'), ['instagram', 'tiktok'], true) ? $request->query('platform') : 'semua';
        $since = now()->subDays($days)->startOfDay();

        $targets = ContentPostTarget::with(['post.user', 'latestSnapshot'])
            ->where('status', ContentPostTarget::PUBLISHED)
            ->where('published_at', '>=', $since)
            ->when($platform !== 'semua', fn ($query) => $query->where('platform', $platform))
            ->get();

        $snaps = $targets->pluck('latestSnapshot')->filter();
        $views = (int) $snaps->sum('views');
        $interactions = $snaps->sum(fn (ContentPostSnapshot $s) => $s->interactions());

        $daily = ContentPostSnapshot::query()
            ->join('content_post_targets', 'content_post_targets.id', '=', 'content_post_snapshots.content_post_target_id')
            ->whereIn('content_post_snapshots.content_post_target_id', $targets->pluck('id'))
            ->where('content_post_snapshots.captured_on', '>=', $since)
            ->selectRaw('content_post_snapshots.captured_on, content_post_targets.platform, sum(content_post_snapshots.views) as views')
            ->groupBy('content_post_snapshots.captured_on', 'content_post_targets.platform')
            ->orderBy('content_post_snapshots.captured_on')
            ->get();
        $chartDates = $daily->pluck('captured_on')->map(fn ($date) => Carbon::parse($date)->toDateString())->unique()->sort()->values();
        $chartPlatforms = $platform === 'semua' ? ['instagram', 'tiktok'] : [$platform];

        $creators = $targets->groupBy(fn ($t) => ($t->options['imported'] ?? false) ? 'brand_imported' : $t->post->user_id)->map(function ($group, $owner) {
            $s = $group->pluck('latestSnapshot')->filter();
            $v = (int) $s->sum('views');

            return [
                'name' => $owner === 'brand_imported' ? 'Konten brand (impor)' : ($group->first()->post->user?->fullname ?? $group->first()->post->user?->name ?? '—'),
                'posts' => $group->pluck('content_post_id')->unique()->count(),
                'targets' => $group->count(),
                'views' => $v,
                'er' => $v ? $s->sum(fn ($x) => $x->interactions()) / $v * 100 : null,
            ];
        })->sortByDesc('views')->values();

        return view('content.insights', [
            'days' => $days,
            'platform' => $platform,
            'platformLabel' => $platform === 'semua' ? 'Semua platform' : ($platform === 'tiktok' ? 'TikTok' : 'Instagram'),
            'syncErrors' => SocialConnection::whereIn('platform', ['instagram', 'tiktok'])
                ->when($platform !== 'semua', fn ($query) => $query->where('platform', $platform))
                ->get()
                ->filter(fn ($connection) => filled($connection->meta['insight_error'] ?? null))
                ->map(fn ($connection) => ['platform' => $connection->platform, 'message' => $connection->meta['insight_error']])
                ->values(),
            'stats' => [
                'views' => $views,
                'er' => $views ? $interactions / $views * 100 : null,
                'posts' => $targets->pluck('content_post_id')->unique()->count(),
                'interactions' => $interactions,
                'synced' => $snaps->count(),
                'targets' => $targets->count(),
            ],
            'chart' => [
                'labels' => $chartDates->map(fn ($date) => Carbon::parse($date)->format('d M'))->all(),
                'datasets' => collect($chartPlatforms)->map(function ($chartPlatform) use ($daily, $chartDates) {
                    $byDate = $daily->where('platform', $chartPlatform)->keyBy(fn ($row) => Carbon::parse($row->captured_on)->toDateString());

                    return [
                        'label' => $chartPlatform === 'tiktok' ? 'TikTok' : 'Instagram',
                        'data' => $chartDates->map(fn ($date) => isset($byDate[$date]) ? (int) $byDate[$date]->views : null)->all(),
                    ];
                })->all(),
            ],
            'top' => $targets->filter(fn ($t) => $t->latestSnapshot)->sortByDesc(fn ($t) => (int) $t->latestSnapshot->views)->take(10)->values(),
            'creators' => $creators,
        ]);
    }
}
