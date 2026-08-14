<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class DownlineOrderController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $orders = PurchaseOrder::query()
            ->where('seller_id', $user->id)                 // KUNCI: hanya pesanan di mana dia penjual
            ->with('user')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('pesanan_downline.index', ['orders' => $orders]);
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder)
    {
        $user = $request->user();
        // INTI KEAMANAN: hanya upline yang JADI PENJUAL di PO ini boleh lihat.
        // seller_id null (PO ke HQ) atau seller_id mitra lain → 403 otomatis.
        abort_unless($purchaseOrder->seller_id === $user->id, 403, 'Ini bukan pesanan downline Anda.');

        $purchaseOrder->load(['items', 'user']);

        // Pre-cek stok upline per item (biar pesan rapi, bukan exception generik).
        $stok = Inventory::where('user_id', $user->id)->pluck('quantity', 'product_id');
        $kurang = [];
        foreach ($purchaseOrder->items as $item) {
            $tersedia = (int) ($stok[$item->product_id] ?? 0);
            if ($tersedia < (int) $item->qty) {
                $kurang[] = ['nama' => $item->product_name, 'tersedia' => $tersedia, 'butuh' => (int) $item->qty];
            }
        }

        return view('pesanan_downline.show', [
            'po' => $purchaseOrder,
            'stokKurang' => $kurang,      // [] = cukup
        ]);
    }

    /** Upline menyetujui/menolak bukti transfer downline — sama seperti PurchaseOrderController::verifyPayment (verifierId = user yang login), lewat service yang sama. */
    public function verifyPayment(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderService $service): RedirectResponse
    {
        $this->guardOwner($purchaseOrder, $request->user());

        $approve = $request->boolean('approve');
        $service->verifyPayment($purchaseOrder, $approve, $request->user()->id, $request->input('note'));

        return back()->with('status', $approve ? 'Pembayaran diverifikasi — PO ditandai LUNAS.' : 'Bukti pembayaran ditolak.');
    }

    /**
     * Kirim/selesaikan pesanan. PO downline mulai dari 'pending' — updateStatus()
     * tak mengizinkan lompat pending→completed langsung (lihat PurchaseOrder::TRANSITIONS),
     * jadi dipakai advanceStatus() (mekanisme yang sama dipakai aksi massal HQ) supaya
     * berjalan lewat tiap status antara. Gerbang lunas/tempo & transfer stok upline→downline
     * (complete()) tetap berlaku di tiap langkah — fulfill sebelum lunas akan berhenti di
     * status antara terakhir yang sah, tanpa menyentuh stok.
     */
    public function fulfill(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderService $service): RedirectResponse
    {
        $this->guardOwner($purchaseOrder, $request->user());

        // Short-circuit SEBELUM advanceStatus: tanpa ini, PO unpaid tetap maju
        // pending→approved dulu (gerbang lunas hanya berlaku mulai processing)
        // baru berhenti — status kebawa walau tak sampai completed. Dicegah di
        // sini supaya PO unpaid benar-benar tak bergerak sama sekali.
        if (! $purchaseOrder->isPaid() && ! $purchaseOrder->is_tempo) {
            return back()->with('error', 'Pesanan belum lunas — verifikasi pembayaran dulu sebelum kirim.');
        }

        try {
            $service->advanceStatus($purchaseOrder, PurchaseOrder::STATUS_COMPLETED, $request->input('notes'));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Pesanan dikirim & diselesaikan.');
    }

    /** Upline menolak pesanan downline — alasan wajib diisi (beda dari cancel HQ yang opsional). */
    public function reject(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderService $service): RedirectResponse
    {
        $this->guardOwner($purchaseOrder, $request->user());

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $service->cancel($purchaseOrder, $data['reason']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Pesanan ditolak.');
    }

    /** INTI KEAMANAN aksi: hanya upline yang JADI PENJUAL di PO ini boleh bertindak. */
    private function guardOwner(PurchaseOrder $po, $user): void
    {
        abort_unless($po->seller_id === $user->id, 403, 'Ini bukan pesanan downline Anda.');
    }
}
