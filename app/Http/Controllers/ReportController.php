<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $granularity = $request->query('granularity', 'day');

        // ?bulan=YYYY-MM opsional. KOSONG = semua periode (perilaku lama halaman ini).
        $bulan = $this->parseMonth($request->query('bulan'));

        $data = [
            'summary' => $this->reports->summary($user, $bulan),
            'salesTrend' => $this->reports->salesTrend($granularity, 14, $user, $bulan),
            'salesByProduct' => $this->reports->salesByProduct(10, $user),
            'poStatus' => $this->reports->poStatusDistribution($user, $bulan),
            'inventory' => $this->reports->inventoryMonitoring(12),
        ];

        // Partner-breakdown charts + profit are HQ-only.
        if ($user->isStaff()) {
            $data['grossProfit'] = $this->reports->grossProfit();
            // Rincian per mitra — distributor & reseller sekaligus, dengan angka.
            $data['partnerDetail'] = $this->reports->partnerSalesDetail($bulan);
            $data['salesByDistributor'] = $this->reports->salesByPartner(User::ROLE_DISTRIBUTOR);
            $data['salesByRegion'] = $this->reports->salesByRegion();
        }

        $data['granularity'] = $granularity;
        $data['bulan'] = $bulan;
        $data['user'] = $user;

        return view('reports.index', $data);
    }

    /** ?bulan=YYYY-MM → Carbon. Kosong/ngawur = null (semua periode). */
    private function parseMonth(?string $v): ?Carbon
    {
        if (! $v || ! preg_match('/^\d{4}-\d{2}$/', $v)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $v.'-01')->startOfMonth();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** JSON endpoint for chart widgets (same data, machine-readable). */
    public function chartData(Request $request): JsonResponse
    {
        $user = $request->user();
        $granularity = $request->query('granularity', 'day');

        $payload = [
            'summary' => $this->reports->summary($user),
            'salesTrend' => $this->reports->salesTrend($granularity, 14, $user),
            'salesByProduct' => $this->reports->salesByProduct(10, $user),
            'poStatus' => $this->reports->poStatusDistribution($user),
            'inventory' => $this->reports->inventoryMonitoring(12),
        ];

        if ($user->isStaff()) {
            $payload['salesByDistributor'] = $this->reports->salesByPartner(User::ROLE_DISTRIBUTOR);
            $payload['salesByReseller'] = $this->reports->salesByPartner(User::ROLE_RESELLER);
            $payload['partnerDetail'] = $this->reports->partnerSalesDetail();
            $payload['salesByRegion'] = $this->reports->salesByRegion();
        }

        return response()->json($payload);
    }
}
