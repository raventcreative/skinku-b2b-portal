<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use App\Models\PurchaseOrder;
use App\Services\ReportService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private ReportService $reports) {}

    public function index(Request $request)
    {
        $user = $request->user();

        // Limited roles (not staff, not partner — e.g. affiliator) get a minimal
        // dashboard with no sales/stock data, just shortcuts to what they can access.
        if (! $user->isStaff() && ! $user->isPartner()) {
            return view('dashboard.index', ['user' => $user, 'limited' => true]);
        }

        $summary = $this->reports->summary($user);
        $poStatus = $this->reports->poStatusDistribution($user);
        $salesTrend = $this->reports->salesTrend('day', 14, $user);

        // Penjualan per channel — data HQ, hanya untuk staff (mitra lihat PO sendiri).
        $channelSales = $user->isStaff() ? $this->reports->channelSales() : null;

        // Recent POs visible to this user.
        $recentPo = PurchaseOrder::query()
            ->when($user->isPartner(), fn ($q) => $q->where('user_id', $user->id))
            ->latest()
            ->limit(8)
            ->get();

        // Low-stock alerts.
        $lowStock = Inventory::query()
            ->with('product', 'user')
            ->whereColumn('quantity', '<=', 'minimum_stock')
            ->when($user->isPartner(), fn ($q) => $q->where('user_id', $user->id))
            ->limit(10)
            ->get();

        return view('dashboard.index', compact('user', 'summary', 'poStatus', 'salesTrend', 'channelSales', 'recentPo', 'lowStock') + ['limited' => false]);
    }
}
