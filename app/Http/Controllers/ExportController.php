<?php

namespace App\Http\Controllers;

use App\Models\Kol;
use App\Models\KolScreening;
use App\Models\PartnerSale;
use App\Models\PurchaseOrder;
use App\Services\HqStockReportService;
use App\Services\ReportService;
use App\Support\XlsxWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Export laporan ke Excel (.xlsx) — satu controller untuk semua laporan
 * operasional, tiap endpoint di balik permission YANG SAMA dengan halamannya.
 * Angka diekspor numerik (bukan teks berformat) supaya bisa langsung
 * di-SUM/pivot; data & filter mengikuti apa yang tampil di halamannya.
 */
class ExportController extends Controller
{
    public function __construct(
        private ReportService $reports,
        private HqStockReportService $hqStock,
    ) {}

    /** Laporan Penjualan — menghormati ?bulan= (kosong=bulan berjalan, all=semua). */
    public function penjualan(Request $request): BinaryFileResponse
    {
        $user = $request->user();
        $bulan = $this->parseBulan($request->query('bulan'));
        $label = $bulan ? $bulan->format('Y-m') : 'semua-periode';

        $summary = $this->reports->summary($user, $bulan);
        $sheets = [
            'Ringkasan' => [
                'headers' => ['Metrik', 'Nilai'],
                'rows' => [
                    ['Periode', $bulan ? $bulan->translatedFormat('F Y') : 'Semua periode'],
                    [$user->isPartner() ? 'Total Pembelian (selesai)' : 'Penjualan PO (tagihan)', (float) $summary['total_sales']],
                    ['Total PO', (int) $summary['total_po']],
                ],
            ],
            'Tren' => [
                'headers' => ['Periode', 'Penjualan', 'Jumlah Order'],
                'rows' => collect($this->reports->salesTrend('day', $bulan ? $bulan->daysInMonth : 31, $user, $bulan))
                    ->map(fn ($r) => [$r['label'], (float) $r['total'], (int) $r['orders']]),
            ],
            'Top Produk' => [
                'headers' => ['Produk', 'Qty', 'Omzet'],
                'rows' => collect($this->reports->salesByProduct(10, $user, $bulan))
                    ->map(fn ($r) => [$r['label'], (int) $r['qty'], (float) $r['revenue']]),
            ],
        ];

        if ($user->isStaff()) {
            $sheets['Per Mitra'] = [
                'headers' => ['Mitra', 'Peran', 'Jumlah Order', 'Omzet', 'Rata-rata/Order'],
                'rows' => collect($this->reports->partnerSalesDetail($bulan))
                    ->map(fn ($r) => [$r['label'], $r['role'] ?? '-', (int) $r['orders'], (float) $r['revenue'], (float) $r['avg']]),
            ];
        }

        return XlsxWriter::download("laporan-penjualan-{$label}.xlsx", $sheets);
    }

    /** Laporan Stok HQ — mode & tanggal sama dengan halamannya. */
    public function stokHq(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()->canDo('manage_hq_stock'), 403);

        $mode = $request->input('mode') === 'bulanan' ? 'bulanan' : 'harian';
        try {
            $anchor = Carbon::parse($request->input('date', 'today'));
        } catch (\Throwable) {
            $anchor = Carbon::today();
        }

        $report = $this->hqStock->report($mode, $anchor);

        $rows = collect($report['rows'])->map(fn ($r) => [
            $r['product']->name, $r['product']->sku,
            (int) $r['awal'], (int) $r['produksi'], (int) $r['masuk_lain'],
            (int) $r['tiktok'], (int) $r['shopee'], (int) $r['reseller'],
            (int) $r['keluar_lain'], (int) $r['penyesuaian'], (int) $r['akhir'],
        ]);
        $t = $report['totals'];
        $rows->push(['TOTAL', '', (int) $t['awal'], (int) $t['produksi'], (int) $t['masuk_lain'],
            (int) $t['tiktok'], (int) $t['shopee'], (int) $t['reseller'],
            (int) $t['keluar_lain'], (int) $t['penyesuaian'], (int) $t['akhir']]);

        return XlsxWriter::download('laporan-stok-hq-'.$mode.'-'.$anchor->format('Y-m-d').'.xlsx', [
            'Mutasi Stok HQ' => [
                'headers' => ['Produk', 'SKU', 'Stok Awal', 'Produksi', 'Masuk Lain',
                    'TikTok', 'Shopee', 'Reseller', 'Keluar Lain', 'Penyesuaian', 'Stok Akhir'],
                'rows' => $rows,
            ],
        ]);
    }

    /** Listing KOL — replika sheet Excel, seluruh screening. */
    public function listingKol(): BinaryFileResponse
    {
        $rows = KolScreening::query()->with('kol')
            ->orderByDesc('tanggal_listing')->orderByDesc('id')->get()
            ->map(fn (KolScreening $s) => [
                $s->tanggal_listing->translatedFormat('M Y'),
                $s->tanggal_listing->format('Y-m-d'),
                '@'.($s->kol->tiktok_username ?? '?'),
                $s->kol->tiktok_link ?? '',
                (int) ($s->kol->followers ?? 0),
                $s->ratecard,   // null (belum nego) = sel kosong, bukan 0 palsu
                ...$s->views(),
                $s->total_views,
                $s->rata_views,
                $s->median_views,
                $s->cpm_rata,
                $s->cpm_median,
                $s->verdict_rata,
                $s->verdict_median,
                $s->gmv_estimate,
                $s->viral_label,
                $s->fake_label ?? '',
                $s->kol->agency ?? '',
            ]);

        return XlsxWriter::download('listing-kol-'.now()->format('Y-m-d').'.xlsx', [
            'Listing KOL' => [
                'headers' => ['Bulan Listing', 'Tanggal Listing', 'Username', 'Link', 'Followers', 'Ratecard',
                    'Views 1', 'Views 2', 'Views 3', 'Views 4', 'Views 5', 'Views 6', 'Views 7',
                    'Total Views', 'Avg Views/Vid', 'Avg Median/Vid', 'CPM AVG (Mean)', 'CPM AVG (Median)',
                    'Indikator Mean', 'Indikator Median', 'Estimasi GMV', 'Viral', 'Fake', 'Agency'],
                'rows' => $rows,
            ],
        ]);
    }

    /** Database KOL — satu baris per KOL + angka screening terakhirnya. */
    public function databaseKol(): BinaryFileResponse
    {
        $rows = Kol::query()->with('latestScreening')->orderBy('tiktok_username')->get()
            ->map(function (Kol $k) {
                $ls = $k->latestScreening;

                return [
                    '@'.$k->tiktok_username, (int) $k->followers, $k->level,
                    $k->kategori ?? '', $k->provinsi ?? '', $k->agency ?? '', $k->status,
                    $ls?->ratecard, $ls?->median_views, $ls?->ratio,
                    $ls?->cpm_median, $ls?->cpv_median, $ls?->verdict_median ?? 'belum discreening',
                    $ls?->gmv_estimate,
                ];
            });

        return XlsxWriter::download('database-kol-'.now()->format('Y-m-d').'.xlsx', [
            'Database KOL' => [
                'headers' => ['Username', 'Followers', 'Level', 'Kategori', 'Provinsi', 'Agency', 'Status',
                    'Ratecard', 'Median Views', 'Ratio %', 'CPM (Median)', 'CPV', 'Verdict', 'Estimasi GMV'],
                'rows' => $rows,
            ],
        ]);
    }

    /** Riwayat penjualan mitra ke customer — hanya milik mitra yang login. */
    public function partnerSales(Request $request): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless($user->isPartner(), 403, 'Hanya mitra yang punya riwayat penjualan customer.');

        $bulan = $this->parseBulan($request->query('bulan'));

        $sales = PartnerSale::query()->with('items')
            ->where('user_id', $user->id)
            ->when($bulan, fn ($q) => $q->whereBetween('sold_at', [
                $bulan->copy()->startOfMonth()->toDateString(), $bulan->copy()->endOfMonth()->toDateString(),
            ]))
            ->orderByDesc('sold_at')->orderByDesc('id')->get();

        // Satu baris per ITEM — nomor nota berulang; total nota di tiap baris
        // supaya pivot per-nota tetap gampang.
        $rows = [];
        foreach ($sales as $sale) {
            foreach ($sale->items as $it) {
                $rows[] = [
                    $sale->sale_number, $sale->sold_at->format('Y-m-d'), $sale->customer_name ?? '',
                    $it->product_name, (int) $it->qty, (float) $it->unit_price, (float) $it->total_price,
                    (float) $sale->total_amount,
                ];
            }
        }

        return XlsxWriter::download('penjualan-customer-'.($bulan ? $bulan->format('Y-m') : 'semua').'.xlsx', [
            'Penjualan' => [
                'headers' => ['No. Nota', 'Tanggal', 'Customer', 'Produk', 'Qty', 'Harga Satuan', 'Subtotal', 'Total Nota'],
                'rows' => $rows,
            ],
        ]);
    }

    /** Daftar PO — staf melihat semua, mitra HANYA miliknya sendiri. */
    public function purchaseOrders(Request $request): BinaryFileResponse
    {
        $user = $request->user();

        $rows = PurchaseOrder::query()
            ->when($user->isPartner(), fn ($q) => $q->where('user_id', $user->id))
            ->orderByDesc('id')->get()
            ->map(fn (PurchaseOrder $po) => [
                $po->po_number,
                $po->orderDate()?->format('Y-m-d') ?? $po->created_at->format('Y-m-d'),
                $po->company_name ?? '', $po->user_role ?? '',
                $po->status, $po->payment_status,
                (float) $po->subtotal, (float) $po->shipping_cost, (float) $po->discount, (float) $po->total_amount,
                $po->completed_at?->format('Y-m-d') ?? '',
            ]);

        return XlsxWriter::download('purchase-orders-'.now()->format('Y-m-d').'.xlsx', [
            'Purchase Orders' => [
                'headers' => ['No. PO', 'Tanggal Order', 'Mitra', 'Peran', 'Status', 'Bayar',
                    'Subtotal', 'Ongkir', 'Diskon', 'Total', 'Selesai'],
                'rows' => $rows,
            ],
        ]);
    }

    /** kosong/ngawur = bulan berjalan; 'all' = semua periode — sama dengan halaman laporan. */
    private function parseBulan(?string $v): ?Carbon
    {
        if ($v === 'all') {
            return null;
        }
        if (! $v || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v)) {
            return Carbon::now()->startOfMonth();
        }

        return Carbon::createFromFormat('Y-m-d', $v.'-01')->startOfMonth();
    }
}
