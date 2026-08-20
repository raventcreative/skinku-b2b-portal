<?php

namespace App\Http\Controllers;

use App\Services\HqStockReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class HqStockReportController extends Controller
{
    public function __construct(private HqStockReportService $service) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->canDo('manage_hq_stock'), 403);

        $mode = $request->input('mode') === 'bulanan' ? 'bulanan' : 'harian';
        $anchor = $this->parseAnchor($request->input('date'), $mode);

        // Web menampilkan SEMUA SKU (termasuk stok 0 yang idle); user bisa
        // menyembunyikan yang kosong lewat tombol di halaman (client-side).
        // Export tetap default (tanpa yang kosong).
        $report = $this->service->report($mode, $anchor, includeEmpty: true);

        return view('inventory.hq_report', $report + ['anchor' => $anchor->format('Y-m-d')]);
    }

    private function parseAnchor(?string $date, string $mode): Carbon
    {
        try {
            $c = $date ? Carbon::parse($date) : Carbon::today();
        } catch (\Throwable $e) {
            $c = Carbon::today();
        }

        return $c;
    }
}
