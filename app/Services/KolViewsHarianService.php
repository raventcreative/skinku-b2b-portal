<?php

namespace App\Services;

use App\Models\Kol;
use App\Models\KolContentDailySnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Report views HARIAN video SKINKU per kreator. Sumber: potret harian kumulatif
 * bulan (kol_content_daily_snapshots, diambil jam 04:00). Views tanggal D =
 * potret (D+1) − potret sebelumnya untuk video yang sama:
 *  - ganti bulan (period beda) → angka bulan baru = views sejak tanggal 1;
 *  - video tanpa potret sebelumnya: dihitung penuh bila baru diposting (≤ D),
 *    selain itu hanya jadi titik awal (tak dihitung, biar tak menggelembung);
 *  - selisih negatif (koreksi TikTok) → 0.
 */
class KolViewsHarianService
{
    /**
     * @return array{dates:array<int,string>,rows:Collection,totals:array<string,int>,mulai:?string}
     */
    public function report(Carbon $from, Carbon $to, ?int $kolId = null): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();
        $dates = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $dates[] = $d->toDateString();
        }

        $snaps = KolContentDailySnapshot::query()
            ->whereBetween('captured_on', [$from->toDateString(), $to->copy()->addDay()->toDateString()])
            ->when($kolId, fn ($q) => $q->where('kol_id', $kolId))
            ->orderBy('captured_on')
            ->get(['kol_id', 'content_id', 'period', 'captured_on', 'posted_at', 'views', 'gmv']);

        // [kol_id][tanggal] => ['views','gmv','videos'=>set]
        $agg = [];
        foreach ($snaps->groupBy('content_id') as $list) {
            $prev = null;
            foreach ($list as $s) {
                $day = Carbon::parse($s->captured_on)->subDay()->toDateString(); // potret pagi = views kemarin
                if ($prev && $prev->period === $s->period) {
                    $dv = max(0, $s->views - $prev->views);
                    $dg = max(0, $s->gmv - $prev->gmv);
                } elseif ($prev) {
                    [$dv, $dg] = [$s->views, $s->gmv]; // bulan baru: kumulatif sejak tgl 1
                } elseif ($s->posted_at && $s->posted_at->toDateString() >= $day) {
                    [$dv, $dg] = [$s->views, $s->gmv]; // video baru diposting
                } else {
                    $prev = $s; // titik awal saja

                    continue;
                }
                $prev = $s;
                if (! in_array($day, $dates, true)) {
                    continue;
                }
                $cell = &$agg[$s->kol_id][$day];
                $cell['views'] = ($cell['views'] ?? 0) + $dv;
                $cell['gmv'] = ($cell['gmv'] ?? 0) + $dg;
                $cell['videos'][$s->content_id] = true;
                unset($cell);
            }
        }

        $kols = Kol::whereIn('id', array_keys($agg))->get(['id', 'tiktok_username', 'name', 'role', 'is_gapok'])->keyBy('id');
        $rows = collect($agg)->map(function (array $days, int $kid) use ($kols, $dates) {
            $views = [];
            foreach ($dates as $d) {
                $views[$d] = $days[$d]['views'] ?? 0;
            }

            return [
                'kol' => $kols->get($kid),
                'views' => $views,
                'total_views' => array_sum($views),
                'total_gmv' => array_sum(array_map(fn ($c) => $c['gmv'] ?? 0, $days)),
                'videos' => count(array_unique(array_merge(...array_map(fn ($c) => array_keys($c['videos'] ?? []), array_values($days))))),
            ];
        })->filter(fn ($r) => $r['kol'] !== null)->sortByDesc('total_views')->values();

        $totals = [];
        foreach ($dates as $d) {
            $totals[$d] = (int) $rows->sum(fn ($r) => $r['views'][$d]);
        }

        return [
            'dates' => $dates,
            'rows' => $rows,
            'totals' => $totals,
            'mulai' => KolContentDailySnapshot::min('captured_on'),
        ];
    }
}
