<?php

namespace App\Services;

use App\Models\Kol;
use App\Models\KolContentDailySnapshot;
use Illuminate\Database\Eloquent\Builder;
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
 * videos() = rincian per video satu kreator; angkanya sama persis dgn barisnya di report().
 */
class KolViewsHarianService
{
    /**
     * @return array{dates:array<int,string>,rows:Collection,totals:array<string,int>,mulai:?string}
     */
    public function report(Carbon $from, Carbon $to): array
    {
        [$from, $to, $dates] = $this->rentang($from, $to);
        $diposting = $this->diunggah($from, $to)
            ->groupBy('kol_id')
            ->selectRaw('kol_id, COUNT(DISTINCT content_id) AS n')
            ->pluck('n', 'kol_id');

        // [kol_id][tanggal] => ['views','gmv']
        $agg = [];
        foreach ($this->perVideo($from, $to, $dates) as $v) {
            foreach ($v['days'] as $day => $c) {
                $cell = &$agg[$v['kol_id']][$day];
                $cell['views'] = ($cell['views'] ?? 0) + $c['views'];
                $cell['gmv'] = ($cell['gmv'] ?? 0) + $c['gmv'];
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

        return [
            'dates' => $dates,
            'rows' => $rows,
            'totals' => $this->totals($rows, $dates),
            'mulai' => KolContentDailySnapshot::min('captured_on'),
        ];
    }

    /**
     * Rincian per video satu kreator di rentang. Video tanpa views/GMV di rentang hanya tampil bila diunggah
     * di rentang ('baru' — jumlahnya = kolom Diposting kreator itu).
     *
     * @return array{dates:array<int,string>,rows:Collection,totals:array<string,int>}
     */
    public function videos(Kol $kol, Carbon $from, Carbon $to): array
    {
        [$from, $to, $dates] = $this->rentang($from, $to);
        $vids = $this->perVideo($from, $to, $dates, $kol->id);
        $baru = $this->diunggah($from, $to)->where('kol_id', $kol->id)->orderBy('captured_on')
            ->get(['content_id', 'title', 'posted_at'])->keyBy('content_id'); // keyBy: potret terbaru yang dipakai
        foreach ($baru as $id => $s) {
            $vids[$id] ??= ['title' => $s->title, 'posted_at' => $s->posted_at, 'days' => []];
        }

        $rows = collect($vids)->map(function (array $v, $id) use ($dates, $baru) {
            $views = [];
            foreach ($dates as $d) {
                $views[$d] = $v['days'][$d]['views'] ?? 0;
            }

            return [
                'content_id' => (string) $id,
                'title' => $v['title'],
                'posted_at' => $v['posted_at'],
                'baru' => $baru->has($id),
                'views' => $views,
                'total_views' => array_sum($views),
                'total_gmv' => array_sum(array_column($v['days'], 'gmv')),
            ];
        })->filter(fn ($r) => $r['baru'] || $r['total_views'] > 0 || $r['total_gmv'] > 0)
            ->sortByDesc('total_views')->values();

        return ['dates' => $dates, 'rows' => $rows, 'totals' => $this->totals($rows, $dates)];
    }

    /** @return array<string,int> total views per tanggal dari baris yang diberikan */
    public function totals(Collection $rows, array $dates): array
    {
        $totals = [];
        foreach ($dates as $d) {
            $totals[$d] = (int) $rows->sum(fn ($r) => $r['views'][$d]);
        }

        return $totals;
    }

    /** @return array{0:Carbon,1:Carbon,2:array<int,string>} */
    private function rentang(Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();
        $dates = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $dates[] = $d->toDateString();
        }

        return [$from, $to, $dates];
    }

    /** Potret video yang DIUNGGAH di rentang (posted_at TikTok), dari potret mana pun — termasuk yang views-nya baru datang setelah rentang. */
    private function diunggah(Carbon $from, Carbon $to): Builder
    {
        return KolContentDailySnapshot::query()
            ->where('posted_at', '>=', $from->toDateTimeString())
            ->where('posted_at', '<', $to->copy()->addDay()->toDateTimeString());
    }

    /**
     * Views & GMV harian per video (aturan: docblock kelas), hanya hari di dalam rentang.
     *
     * @return array<string, array{kol_id:int, title:?string, posted_at:?Carbon, days:array<string,array{views:int,gmv:int}>}>
     */
    private function perVideo(Carbon $from, Carbon $to, array $dates, ?int $kolId = null): array
    {
        $jendela = [$from->toDateString(), $to->copy()->addDay()->toDateString()];
        // Tanggal potret yang tercatat — dari SEMUA kreator, juga saat menyaring satu kreator: "kemarin sudah
        // dipotret" berlaku global, jadi rincian per kreator sama persis dgn barisnya di report().
        $tercatat = KolContentDailySnapshot::whereBetween('captured_on', $jendela)->distinct()->pluck('captured_on')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())->flip();
        $snaps = KolContentDailySnapshot::query()
            ->whereBetween('captured_on', $jendela)
            ->when($kolId, fn ($q) => $q->where('kol_id', $kolId))
            ->orderBy('captured_on')
            ->get(['kol_id', 'content_id', 'title', 'period', 'captured_on', 'posted_at', 'views', 'gmv']);

        $out = [];
        foreach ($snaps->groupBy('content_id') as $id => $list) {
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
                $v = &$out[$id];
                $v['kol_id'] = $s->kol_id;
                $v['title'] = $s->title ?? ($v['title'] ?? null);
                $v['posted_at'] = $s->posted_at ?? ($v['posted_at'] ?? null);
                $v['days'][$day]['views'] = ($v['days'][$day]['views'] ?? 0) + $dv;
                $v['days'][$day]['gmv'] = ($v['days'][$day]['gmv'] ?? 0) + $dg;
                unset($v);
            }
        }

        return $out;
    }
}
