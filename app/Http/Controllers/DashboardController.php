<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Inventory;
use App\Models\PurchaseOrder;
use App\Models\Withdrawal;
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
        // Filter tanggal KHUSUS section ini (?ch_dari & ?ch_sampai) — lihat parseChannelDates.
        [$chFrom, $chSampai] = $this->parseChannelDates($request);
        $channelSales = $user->isStaff() ? $this->reports->channelSales($bulan, $chFrom, $chSampai) : null;

        // Grand Total omzet SETAHUN (semua channel) — hanya staff.
        $yearlyOmzet = $user->isStaff() ? $this->reports->yearlyOmzet($bulan) : null;

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

        // "Perlu Tindakan" — penarikan komisi yang menunggu diproses. Hanya untuk
        // staf yang berwenang memproses (izin process_withdrawal). Selain itu kosong
        // → panel tak tampil.
        $pendingWithdrawals = $user->canDo('process_withdrawal')
            ? Withdrawal::where('status', 'diajukan')->with('mitra')->orderByDesc('id')->limit(8)->get()
            : collect();

        // "Inbox" PO — pesanan yang butuh tindakan: baru masuk (pending) atau bukti
        // bayar perlu diverifikasi (awaiting_verification). Staf HQ (update_po_status)
        // lihat PO langsung-HQ; mitra-penjual (process_downline_po) lihat PO downline.
        $canHqPo = $user->canDo('update_po_status');
        $canDownlinePo = $user->canDo('process_downline_po');
        $actionablePos = ($canHqPo || $canDownlinePo)
            ? PurchaseOrder::query()
                ->with('user')
                ->where(function ($q) use ($user, $canHqPo, $canDownlinePo) {
                    if ($canHqPo) {
                        $q->orWhereNull('seller_id');
                    }
                    if ($canDownlinePo) {
                        $q->orWhere('seller_id', $user->id);
                    }
                })
                ->where(fn ($q) => $q
                    ->where('status', PurchaseOrder::STATUS_PENDING)
                    ->orWhere('payment_status', PurchaseOrder::PAYMENT_AWAITING))
                ->whereNotIn('status', [PurchaseOrder::STATUS_COMPLETED, PurchaseOrder::STATUS_CANCELLED])
                ->latest()
                ->limit(8)
                ->get()
            : collect();

        return view('dashboard.index', compact('user', 'summary', 'poStatus', 'salesTrend', 'channelSales', 'yearlyOmzet', 'bulan', 'chFrom', 'chSampai', 'recentPo', 'lowStock', 'pendingWithdrawals', 'actionablePos') + ['limited' => false] + $announce);
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

    /**
     * ?ch_dari & ?ch_sampai (YYYY-MM-DD) → [from, to] Carbon (startOfDay) atau null.
     * Satu tanggal saja = hari itu. Null = default seluruh bulan (di channelSales).
     *
     * @return array{0:?Carbon,1:?Carbon}
     */
    private function parseChannelDates(Request $request): array
    {
        $re = '/^\d{4}-\d{2}-\d{2}$/';
        $from = preg_match($re, (string) $request->query('ch_dari')) ? Carbon::parse($request->query('ch_dari'))->startOfDay() : null;
        $to = preg_match($re, (string) $request->query('ch_sampai')) ? Carbon::parse($request->query('ch_sampai'))->startOfDay() : null;
        $from ??= $to?->copy();
        $to ??= $from?->copy();

        return [$from, $to];
    }

    /** Fragment HTML section "Penjualan per Channel" untuk update via AJAX (filter tanggal tanpa reload). */
    public function channelSalesFragment(Request $request)
    {
        $user = $request->user();
        abort_unless($user->isStaff(), 403);

        $bulan = $this->parseMonth($request->query('bulan'));
        [$chFrom, $chSampai] = $this->parseChannelDates($request);
        $channelSales = $this->reports->channelSales($bulan, $chFrom, $chSampai);

        return view('dashboard._channel-sales', compact('channelSales', 'bulan', 'chFrom', 'chSampai'));
    }
}
