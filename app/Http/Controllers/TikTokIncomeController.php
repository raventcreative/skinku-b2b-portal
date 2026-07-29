<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use App\Services\TikTokIncomeReportService;
use App\Support\XlsxWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Laporan Income TikTok (Fase 1, jalur UPLOAD) — migrasi report "Tiktok income"
 * dari bot n8n. Upload "Semua pesanan" (.csv) + "income" (.xlsx) → join by Order
 * ID → tabel + unduh Excel. Report-only (tak menyentuh stok). Hasil disimpan di
 * session (stateless, tanpa tabel). Izin: manage_tiktok. Lihat TIKTOK_INCOME_SPEC.md.
 */
class TikTokIncomeController extends Controller
{
    private const SESSION = 'tiktok_income_report';

    public function __construct(private TikTokIncomeReportService $service) {}

    public function form(Request $request)
    {
        return view('tiktok.income', ['report' => $request->session()->get(self::SESSION)]);
    }

    public function process(Request $request): RedirectResponse
    {
        $request->validate([
            'orders' => ['required', 'file', 'max:30720'],   // csv "Semua pesanan" (bisa belasan MB)
            'income' => ['required', 'file', 'max:8192'],     // xlsx income
        ]);

        $orders = $request->file('orders');
        $income = $request->file('income');
        if (strtolower($orders->getClientOriginalExtension()) !== 'csv') {
            return back()->with('error', 'File pesanan harus .csv ("Semua pesanan").');
        }
        if (strtolower($income->getClientOriginalExtension()) !== 'xlsx') {
            return back()->with('error', 'File income harus .xlsx.');
        }

        try {
            $report = $this->service->fromFiles($orders->getRealPath(), $income->getRealPath());
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal memproses file: '.$e->getMessage());
        }

        $request->session()->put(self::SESSION, $report);
        AuditService::log(action: 'tiktok_income_report', targetType: 'tiktok', after: $report['summary']);

        return redirect()->route('tiktok.income')
            ->with('status', "Laporan dibuat: {$report['summary']['income_orders']} order income diproses.");
    }

    public function download(Request $request): BinaryFileResponse|RedirectResponse
    {
        $report = $request->session()->get(self::SESSION);
        if (! $report) {
            return redirect()->route('tiktok.income')->with('error', 'Belum ada laporan — upload dulu.');
        }

        $columns = $report['columns'];
        $headers = array_merge(['Order ID', 'Waktu', 'Type', 'Total Pendapatan', 'Total Biaya', 'Settlement'], $columns);

        $rows = [];
        foreach ($report['rows'] as $r) {
            $line = [$r['order_id'], $r['time'], $r['type'], $r['revenue'], $r['fee'], $r['settlement']];
            foreach ($columns as $col) {
                $qty = $r['cat_qty'][$col] ?? 0;
                $line[] = $qty > 0 ? $qty : '';   // kosong kalau 0 (samain file "Tiktok income")
            }
            $rows[] = $line;
        }

        return XlsxWriter::download('Laporan Income TikTok.xlsx', ['Income' => ['headers' => $headers, 'rows' => $rows]]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION);

        return redirect()->route('tiktok.income')->with('status', 'Laporan dibersihkan.');
    }
}
