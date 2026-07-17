<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Catat penjualan distributor yang SUDAH terjadi (back-date) — mis. memasukkan
 * riwayat dari Excel. Sengaja terpisah dari alur PO mitra yang sudah jalan.
 *
 * Pengaman: order sebelum batas tanggal TIDAK memotong stok, karena barangnya
 * sudah keluar sebelum stok opname dan sudah terhitung di sana.
 */
class BackdatedSaleController extends Controller
{
    public function __construct(private PurchaseOrderService $service) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->canDo('manage_hq_stock'), 403);

        return view('purchase_orders.backdated', [
            'partners' => User::whereIn('role', [User::ROLE_DISTRIBUTOR, User::ROLE_RESELLER])
                ->where('status', User::STATUS_ACTIVE)->orderBy('fullname')->get(),
            // Kedua harga tier dikirim: harga isi-otomatis mengikuti mitra terpilih.
            'products' => Product::where('status', Product::STATUS_ACTIVE)->orderBy('name')
                ->get(['id', 'name', 'sku', 'price_distributor', 'price_reseller']),
            'cutoff' => $this->service->stockCutoff(),
            'recent' => PurchaseOrder::whereNotNull('order_date')->with('user')->latest('id')->limit(10)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canDo('manage_hq_stock'), 403);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'order_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'min:0'],
            // Harga manual: kosong = pakai harga tier saat ini.
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $buyer = User::findOrFail($data['user_id']);
        abort_unless($buyer->isPartner(), 422, 'Pembeli harus distributor/reseller.');

        try {
            $po = $this->service->recordBackdatedSale(
                buyer: $buyer,
                lines: $data['items'],
                orderDate: Carbon::parse($data['order_date']),
                notes: $data['notes'] ?? null,
                creatorId: $request->user()->id,
            );
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['items' => $e->getMessage()]);
        }

        $msg = "Penjualan {$po->po_number} tercatat (".$po->orderDate()->format('d M Y').').';
        $msg .= $po->stock_skipped
            ? ' Stok TIDAK dipotong — order pra-opname, sudah terhitung di opname.'
            : ' Stok dipotong seperti biasa.';

        return redirect()->route('backdated-sales.index')->with('status', $msg);
    }

    /** Set batas tanggal potong stok PO. */
    public function setCutoff(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canDo('manage_hq_stock'), 403);

        $data = $request->validate(['po_deduct_from' => ['nullable', 'date']]);
        AppSetting::put(AppSetting::PO_DEDUCT_FROM, $data['po_deduct_from'] ?? null);

        return back()->with('status', $data['po_deduct_from'] ?? null
            ? 'Batas disimpan: PO sebelum '.Carbon::parse($data['po_deduct_from'])->format('d M Y').' tidak memotong stok.'
            : 'Batas dihapus — SEMUA PO akan memotong stok (hati-hati dobel dengan opname).');
    }
}
