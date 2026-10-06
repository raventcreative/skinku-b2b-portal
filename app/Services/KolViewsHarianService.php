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
 *  - video tanpa potret sebelumnya: dihitung penuh bila baru diposting (≤ D) ATAU
 *    potret hari D (kemarin) sudah tercatat tapi video ini tak ada di sana — TikTok
 *    hanya mengirim video yang dapat views bulan ini, jadi absen = 0 dan seluruh
 *    angkanya views hari D. Bila hari D belum dipotret (awal pencatatan / awal
 *    jendela / celah sync) → hanya titik awal (tak dihitung, biar tak menggelembung);
 *  - selisih negatif (koreksi TikTok) → 0.
 * Kolom "diposting" = jumlah video yang DIUNGGAH di rentang (posted_at dari TikTok),
 * dari potret mana pun — views video lama tetap masuk, tapi videonya tak ikut dihitung.
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
        // Tanggal potret yang tercatat (video mana pun) di jendela ini — utk tahu apakah "kemarin" sudah dipotret.
        $tercatat = $snaps->map(fn ($s) => Carbon::parse($s->captured_on)->toDateString())->unique()->flip();
        // Video yang diunggah di rentang, dari potret mana pun: termasuk yang views-nya baru datang setelah rentang.
        $diposting = KolContentDailySnapshot::query()
            ->where('posted_at', '>=', $from->toDateTimeString())
            ->where('posted_at', '<', $to->copy()->addDay()->toDateTimeString())
            ->when($kolId, fn ($q) => $q->where('kol_id', $kolId))
            ->groupBy('kol_id')
            ->selectRaw('kol_id, COUNT(DISTINCT content_id) AS n')
            ->pluck('n', 'kol_id');

        // [kol_id][tanggal] => ['views','gmv']
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
                } elseif (($s->posted_at && $s->posted_at->toDateString() >= $day) || $tercatat->has($day)) {
                    // Video baru diposting, ATAU kemarin sudah dipotret tapi video ini belum ada di sana (= 0 views
                    // bulan ini s/d kemarin) → seluruh angkanya views hari ini. Dulu angka ini dibuang (undercount
                    // views hari pertama video lama yang aktif lagi).
                    [$dv, $dg] = [$s->views, $s->gmv];
                } else {
                    $prev = $s; // titik awal: kemarin belum dipotret (awal pencatatan/jendela, atau celah sync)

                    continue;
                }
                $prev = $s;
                if (! in_array($day, $dates, true)) {
                    continue;
                }
                $cell = &$agg[$s->kol_id][$day];
                $cell['views'] = ($cell['views'] ?? 0) + $dv;
                $cell['gmv'] = ($cell['gmv'] ?? 0) + $dg;
                unset($cell);
            }
        }
        foreach ($diposting->keys() as $kid) {
            $agg[$kid] ??= []; // mengunggah di rentang tapi belum dapat views → tetap tampil (views 0)
        }

        $kols = Kol::whereIn('id', array_keys($agg))->get(['id', 'tiktok_username', 'name', 'role', 'is_gapok'])->keyBy('id');
        $rows = collect($agg)->map(function (array $days, int $kid) use ($kols, $dates, $diposting) {
            $views = [];
            foreach ($dates as $d) {
                $views[$d] = $days[$d]['views'] ?? 0;
            }

            return [
                'kol' => $kols->get($kid),
                'views' => $views,
                'total_views' => array_sum($views),
                'total_gmv' => array_sum(array_map(fn ($c) => $c['gmv'] ?? 0, $days)),
                'diposting' => (int) $diposting->get($kid, 0),
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
