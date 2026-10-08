<?php

namespace App\Http\Controllers;

use App\Models\Kol;
use App\Services\KolViewsHarianService;
use App\Support\XlsxWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Report views harian video SKINKU per kreator (+ export Excel, + daftar video per kreator). Gate: kol.affiliate.view. */
class KolViewsHarianController extends Controller
{
    public function __construct(private KolViewsHarianService $svc) {}

    public function index(Request $request): View
    {
        [$from, $to] = $this->range($request);
        $rep = $this->svc->report($from, $to);
        $q = mb_strtolower(trim((string) $request->query('q', '')));
        if ($q !== '') {
            $rep['rows'] = $rep['rows']->filter(fn ($r) => str_contains(mb_strtolower($r['kol']->tiktok_username.' '.$r['kol']->name), $q))->values();
            // Baris total ikut hasil pencarian (dulu tetap total semua kreator, tak cocok dgn "Total N kreator").
            $rep['totals'] = $this->svc->totals($rep['rows'], $rep['dates']);
        }
        [$sort, $dir] = $this->urutan($request, $rep['dates']);
        $rep['rows'] = $this->urutkan($rep['rows'], $sort, $dir);

        return view('kols.views_harian', $rep + ['from' => $from, 'to' => $to, 'q' => $q, 'sort' => $sort, 'dir' => $dir]);
    }

    /** Klik kreator → daftar videonya: views harian per video di rentang yang sama. */
    public function show(Request $request, Kol $kol): View
    {
        [$from, $to] = $this->range($request);

        return view('kols.views_harian_kreator', $this->svc->videos($kol, $from, $to) + [
            'kol' => $kol, 'from' => $from, 'to' => $to, 'q' => trim((string) $request->query('q', '')),
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        [$from, $to] = $this->range($request);
        $rep = $this->svc->report($from, $to);
        [$sort, $dir] = $this->urutan($request, $rep['dates']);
        $rows = $this->urutkan($rep['rows'], $sort, $dir)->map(fn ($r) => array_merge(
            ['@'.$r['kol']->tiktok_username, $r['diposting']],
            array_values($r['views']),
            [$r['total_views'], $r['total_gmv']],
        ));

        return XlsxWriter::download('views-harian-skinku-'.$from->format('Ymd').'-'.$to->format('Ymd').'.xlsx', [
            'Views Harian' => [
                'headers' => array_merge(['Kreator', 'Diposting'], array_map(fn ($d) => Carbon::parse($d)->format('d M'), $rep['dates']), ['Total Views', 'GMV (Rp)']),
                'rows' => $rows,
            ],
        ]);
    }

    /**
     * Urutan tabel (header = tautan sort, pola Database KOL). Kolom divalidasi ke daftar putih: kreator, diposting,
     * total, gmv, atau salah satu tanggal di rentang — nilai ngawur jatuh ke default Total terbesar. Arah default:
     * Kreator A→Z, kolom angka terbesar dulu.
     *
     * @return array{0:string,1:string}
     */
    private function urutan(Request $request, array $dates): array
    {
        $sort = $request->query('sort');
        if (! in_array($sort, ['kreator', 'diposting', 'total', 'gmv'], true) && ! in_array($sort, $dates, true)) {
            $sort = 'total';
        }
        $dir = $request->query('dir');

        return [$sort, in_array($dir, ['asc', 'desc'], true) ? $dir : ($sort === 'kreator' ? 'asc' : 'desc')];
    }

    /** Sort stabil: nilai seri tetap berurutan Total terbesar (urutan bawaan report). */
    private function urutkan(Collection $rows, string $sort, string $dir): Collection
    {
        $nilai = match ($sort) {
            'kreator' => fn ($r) => mb_strtolower((string) $r['kol']->tiktok_username),
            'diposting' => fn ($r) => $r['diposting'],
            'total' => fn ($r) => $r['total_views'],
            'gmv' => fn ($r) => $r['total_gmv'],
            default => fn ($r) => $r['views'][$sort] ?? 0, // kolom tanggal
        };

        return $rows->sortBy($nilai, SORT_REGULAR, $dir === 'desc')->values();
    }

    /** Default 14 hari terakhir (s/d kemarin — views hari ini baru terhitung besok pagi). Maks 62 hari. */
    private function range(Request $request): array
    {
        try {
            $to = Carbon::parse($request->query('sampai', now()->subDay()->toDateString()))->startOfDay();
            $from = Carbon::parse($request->query('dari', $to->copy()->subDays(13)->toDateString()))->startOfDay();
        } catch (\Throwable) {
            $to = now()->subDay()->startOfDay();
            $from = $to->copy()->subDays(13);
        }
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > 61) {
            $from = $to->copy()->subDays(61);
        }

        return [$from, $to];
    }
}
