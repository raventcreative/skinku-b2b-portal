<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use App\Services\BusinessReportService;
use App\Support\XlsxWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Generate Report bisnis: halaman laporan (grafik + tabel, "Download PDF" via print
 * browser), export Excel, dan analisis AI (AJAX, terpisah supaya halaman cepat).
 * Gate: view_reports + staff (data HQ lintas channel).
 */
class BusinessReportController extends Controller
{
    public function __construct(private BusinessReportService $svc) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()->isStaff(), 403);

        $report = $request->filled('jenis') ? $this->build($request) : null;

        return view('reports.business', [
            'report' => $report,
            'jenis' => $request->query('jenis', 'bulanan'),
            'jenisList' => BusinessReportService::JENIS,
        ]);
    }

    public function insight(Request $request): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);
        $report = $this->build($request);
        $res = $this->svc->insight($report['insight_input']);

        AuditService::log(action: 'generate_business_report_ai', targetType: 'business_report', targetId: null,
            after: ['periode' => $report['period']['label'], 'ok' => $res['ok']]);

        return response()->json($res);
    }

    public function export(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()->isStaff(), 403);
        $r = $this->build($request);
        $p = $r['period'];
        $pj = $r['penjualan'];

        $sheets = [
            'Ringkasan' => [
                'headers' => ['Metrik', $p['label'], $p['prevLabel'], 'Selisih', '%'],
                'rows' => collect([
                    ['Omzet (selesai + berjalan)', 'omzet'], ['Terealisasi', 'terealisasi'], ['Jumlah order', 'orders'],
                    ['AOV (rata-rata order)', 'aov'], ['Cancel rate (%)', 'cancel_rate'], ['Nilai batal', 'batal'],
                ])->map(fn ($m) => $this->cmpRow($m[0], $pj['now'][$m[1]], $pj['prev'][$m[1]]))->all(),
            ],
            'Per Channel' => [
                'headers' => ['Channel', 'Omzet', 'Omzet sebelumnya', '%', 'Order', 'Cancel rate (%)'],
                'rows' => array_map(fn ($c) => [$c['label'], $c['omzet'], $c['prev'], $this->pct($c['omzet'], $c['prev']), $c['orders'], $c['cancel_rate']], $pj['channels']),
            ],
            'Tren' => [
                'headers' => array_merge(['Tanggal'], array_column($pj['trend']['channels'], 'label')),
                'rows' => array_map(fn ($i, $d) => array_merge([$d], array_map(fn ($c) => $c['data'][$i], $pj['trend']['channels'])),
                    array_keys($pj['trend']['labels']), $pj['trend']['labels']),
            ],
        ];
        $prodRows = [];
        foreach (['semua' => 'Semua', 'reseller' => 'Reseller/PO', 'tiktok' => 'TikTok', 'shopee' => 'Shopee'] as $k => $lbl) {
            foreach ($r['produk']['channels'][$k]['rows'] as $i => $x) {
                $prodRows[] = [$lbl, $i + 1, $x['label'], $x['qty'], $x['prev'], $x['unmapped'] ? 'belum dipetakan' : ''];
            }
        }
        $sheets['Produk Terlaris'] = ['headers' => ['Channel', 'Rank', 'Produk', 'Unit', 'Unit sebelumnya', 'Catatan'], 'rows' => $prodRows];
        $sheets['Mitra'] = [
            'headers' => ['Mitra', 'Jumlah PO', 'Omzet'],
            'rows' => array_map(fn ($m) => [$m['nama'], $m['po'], $m['omzet']], $r['mitra']['top']),
        ];
        $sheets['Mitra Tidak Order'] = [
            'headers' => ['Mitra', 'Role', 'Order terakhir'],
            'rows' => array_map(fn ($m) => [$m['nama'], $m['role'], $m['terakhir']], $r['mitra']['diam']),
        ];
        $sheets['Stok'] = [
            'headers' => ['Produk', 'Stok HQ', 'Terjual periode ini', 'Cukup (hari)'],
            'rows' => array_map(fn ($s) => [$s['nama'], $s['stok'], $s['terjual'], $s['hari_cukup'] ?? '-'], $r['stok']['semua']),
        ];
        if ($r['kol']) {
            $sheets['KOL Affiliate'] = [
                'headers' => ['Kreator', 'GMV', 'Order'],
                'rows' => array_map(fn ($k) => [$k['nama'], $k['gmv'], $k['orders']], $r['kol']['top']),
            ];
        }
        if ($r['keuangan']) {
            $is = $r['keuangan']['is'];
            $sheets['Laba Rugi'] = [
                'headers' => ['Pos ('.$r['keuangan']['bulan'].')', 'Nilai'],
                'rows' => array_merge(
                    [['Penjualan bersih', $is['penjualan_bersih']], ['HPP', $is['hpp']], ['Laba kotor', $is['laba_kotor']],
                        ['Beban operasional', $is['beban_operasional']], ['Laba operasional', $is['operating_income']], ['Laba bersih', $is['net_income']], ['', '']],
                    collect($r['keuangan']['beban_top'])->map(fn ($v, $k) => ['Beban: '.$k, $v])->values()->all(),
                ),
            ];
        }

        return XlsxWriter::download('laporan-bisnis-'.$p['jenis'].'-'.$p['from']->format('Ymd').'-'.$p['to']->format('Ymd').'.xlsx', $sheets);
    }

    private function build(Request $request): array
    {
        $p = $this->svc->period(
            (string) $request->input('jenis', 'bulanan'), $request->input('acuan'),
            $request->input('dari'), $request->input('sampai'),
        );

        return $this->svc->build($p, $request->user());
    }

    private function pct(float $a, float $b): ?float
    {
        return abs($b) > 0.005 ? round(($a - $b) / abs($b) * 100, 1) : null;
    }

    private function cmpRow(string $label, float $a, float $b): array
    {
        return [$label, $a, $b, round($a - $b, 2), $this->pct($a, $b) ?? '-'];
    }
}
