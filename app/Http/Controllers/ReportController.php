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

        $data = [
            'summary' => $this->reports->summary($user),
            'salesTrend' => $this->reports->salesTrend($granularity, 14, $user),
            'salesByProduct' => $this->reports->salesByProduct(10, $user),
            'poStatus' => $this->reports->poStatusDistribution($user),
            'inventory' => $this->reports->inventoryMonitoring(12),
        ];

        // Partner-breakdown charts + profit are HQ-only.
        if ($user->isStaff()) {
            $data['grossProfit'] = $this->reports->grossProfit();
            $data['salesByDistributor'] = $this->reports->salesByPartner(User::ROLE_DISTRIBUTOR);
            $data['salesByReseller'] = $this->reports->salesByPartner(User::ROLE_RESELLER);
            $data['salesByRegion'] = $this->reports->salesByRegion();
        }

        $data['granularity'] = $granularity;
        $data['user'] = $user;

        return view('reports.index', $data);
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
            $payload['salesByRegion'] = $this->reports->salesByRegion();
        }

        return response()->json($payload);
    }
}
