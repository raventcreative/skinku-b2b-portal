<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Inventory;
use App\Models\PurchaseOrder;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function __construct(private ReportService $reports) {}

    public function index(Request $request)
    {
        $user = $request->user();

        // Pengumuman untuk role user: box catatan (nempel) + popup banner. Banner
        // muncul SEKALI per sesi login (pilihan "tiap kali login") — ditandai di
        // session agar tak nongol lagi saat pindah halaman dalam sesi yang sama.
        $announcement = Announcement::where('role', $user->role)->first();
        $showBanner = false;
        if ($announcement && $announcement->bannerVisible()) {
            $seenKey = 'ann_banner_seen_'.$user->role;
            if (! $request->session()->get($seenKey)) {
                $showBanner = true;
                $request->session()->put($seenKey, true);
            }
        }
        $announce = ['announcement' => $announcement, 'showBanner' => $showBanner];

        // Limited roles (not staff, not partner — e.g. affiliator) get a minimal
        // dashboard with no sales/stock data, just shortcuts to what they can access.
        if (! $user->isStaff() && ! $user->isPartner()) {
            return view('dashboard.index', ['user' => $user, 'limited' => true] + $announce);
        }

        // ?bulan=YYYY-MM berlaku untuk SELURUH dashboard; default bulan berjalan.
        $bulan = $this->parseMonth($request->query('bulan'));

        // Dashboard = lintas channel; Laporan Penjualan = khusus PO.
        $summary = $this->reports->summary($user, $bulan, allChannels: true);
        $poStatus = $this->reports->poStatusDistribution($user, $bulan);
        $salesTrend = $this->reports->salesTrend('day', 31, $user, $bulan);

        // Penjualan per channel — data HQ, hanya untuk staff (mitra lihat PO sendiri).
        $channelSales = $user->isStaff() ? $this->reports->channelSales($bulan) : null;

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

        return view('dashboard.index', compact('user', 'summary', 'poStatus', 'salesTrend', 'channelSales', 'bulan', 'recentPo', 'lowStock') + ['limited' => false] + $announce);
    }

    /** ?bulan=YYYY-MM → Carbon. Input ngawur jatuh ke bulan berjalan, bukan error. */
    private function parseMonth(?string $v): Carbon
    {
        if (! $v || ! preg_match('/^\d{4}-\d{2}$/', $v)) {
            return Carbon::now();
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $v.'-01')->startOfMonth();
        } catch (\Throwable $e) {
            return Carbon::now();
        }
    }
}
