<?php

namespace App\Http\Controllers;

use App\Services\KolViewsHarianService;
use App\Support\XlsxWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Report views harian video SKINKU per kreator (+ export Excel). Gate: kol.affiliate.view. */
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
        }

        return view('kols.views_harian', $rep + ['from' => $from, 'to' => $to, 'q' => $q]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        [$from, $to] = $this->range($request);
        $rep = $this->svc->report($from, $to);
        $rows = $rep['rows']->map(fn ($r) => array_merge(
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
