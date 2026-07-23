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

        // SEMUA pengumuman aktif untuk role user: box catatan (nempel, bisa lebih
        // dari satu) + popup banner.
        $anns = Announcement::where('role', $user->role)->orderBy('sort_order')->orderBy('id')->get();
        $boxes = $anns->filter(fn ($a) => $a->noteVisible())->values();
        $popups = $anns->filter(fn ($a) => $a->bannerVisible())->values();

        // Popup tampil lagi bila token berubah. Token = tanggal + sidik jari popup
        // (id:updated_at). Jadi muncul (A) sekali per HARI, DAN (B) langsung lagi
        // begitu ada popup baru/diedit — walau di sesi yang sama. Dalam hari yang
        // sama & popup tak berubah, tak nongol ulang saat pindah halaman.
        $showPopups = false;
        if ($popups->isNotEmpty()) {
            $signature = $popups->map(fn ($a) => $a->id.':'.($a->updated_at?->timestamp ?? 0))->implode(',');
            $token = md5(now()->toDateString().'|'.$signature);
            $seenKey = 'ann_popups_token_'.$user->role;
            if ($request->session()->get($seenKey) !== $token) {
                $showPopups = true;
                $request->session()->put($seenKey, $token);
            }
        }
        $announce = ['boxes' => $boxes, 'popups' => $popups, 'showPopups' => $showPopups];

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
