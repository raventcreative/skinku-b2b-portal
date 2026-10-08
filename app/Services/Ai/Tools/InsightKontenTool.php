<?php

namespace App\Services\Ai\Tools;

use App\Models\ContentPostTarget;
use App\Models\User;
use App\Services\KontenInsightService;

/**
 * Alat BACA: Insight Konten akun brand (menu Konten → Insight Konten), izin content.manage sama dgn halamannya.
 * Angka dari KontenInsightService::ringkasan → sama dgn kartu, top postingan & tabel per creator.
 */
class InsightKontenTool extends BaseTool
{
    public function __construct(private KontenInsightService $svc) {}

    public function name(): string
    {
        return 'insight_konten';
    }

    public function permission(): ?string
    {
        return 'content.manage';
    }

    public function description(): string
    {
        return 'Insight Konten akun brand SKINKU (menu Konten → Insight Konten): performa postingan yang TERBIT dalam '
            .'7/30/90 hari terakhir di Instagram & TikTok — total views, interaksi (like+komen+share+save), engagement '
            .'rate, jumlah postingan, 10 postingan teratas (views, ER, link), dan performa per content creator. Metrik '
            .'ditarik otomatis tiap hari 05:00 (biasanya ada H+1 setelah terbit). Untuk status/jadwal konten pakai pipeline_konten.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'hari' => ['type' => 'integer', 'enum' => KontenInsightService::PERIODS, 'description' => 'Periode terbit: 7, 30, atau 90 hari terakhir. Default 30.'],
                'platform' => ['type' => 'string', 'enum' => ['semua', 'instagram', 'tiktok'], 'description' => 'Default semua.'],
            ],
            'required' => [],
        ];
    }

    public function run(array $args, User $user): array
    {
        $hari = in_array((int) ($args['hari'] ?? 0), KontenInsightService::PERIODS, true) ? (int) $args['hari'] : 30;
        $platform = in_array($args['platform'] ?? null, ['instagram', 'tiktok'], true) ? $args['platform'] : 'semua';
        $r = $this->svc->ringkasan($hari, $platform);
        $s = $r['stats'];

        return array_filter([
            'periode' => "{$hari} hari terakhir",
            'platform' => ['semua' => 'Instagram & TikTok', 'instagram' => 'Instagram', 'tiktok' => 'TikTok'][$platform],
            'total_views' => $s['views'],
            'interaksi' => (int) $s['interactions'],
            'engagement_rate_persen' => $s['er'] !== null ? round($s['er'], 2) : null,
            'postingan' => $s['posts'],
            'postingan_platform_terbit' => $s['targets'],
            'postingan_platform_ada_data' => $s['synced'],
            'catatan' => $s['targets'] > $s['synced']
                ? ($s['targets'] - $s['synced']).' postingan terbit belum punya data insight (metrik biasanya ada H+1; pastikan akun tersambung & izin insight aktif).'
                : null,
            'error_sinkron' => $r['syncErrors']->map(fn ($e) => strtoupper($e['platform']).': '.$e['message'])->all() ?: null,
            'postingan_teratas' => $r['top']->map(fn (ContentPostTarget $t) => array_filter([
                'judul' => $t->post?->title,
                'platform' => $t->platformLabel(),
                'terbit' => $t->published_at?->toDateString(),
                'views' => $t->latestSnapshot->views,
                'like' => $t->latestSnapshot->likes,
                'komen' => $t->latestSnapshot->comments,
                'share' => $t->latestSnapshot->shares,
                'save' => $t->latestSnapshot->saves,
                'engagement_rate_persen' => ($er = $t->latestSnapshot->er()) !== null ? round($er, 2) : null,
                'link' => $t->permalink,
            ], fn ($v) => $v !== null))->all(),
            'per_kreator' => $r['creators']->map(fn ($c) => [
                'kreator' => $c['name'],
                'postingan' => $c['posts'],
                'postingan_platform' => $c['targets'],
                'views' => $c['views'],
                'engagement_rate_persen' => $c['er'] !== null ? round($c['er'], 2) : null,
            ])->all(),
        ], fn ($v) => $v !== null);
    }
}
