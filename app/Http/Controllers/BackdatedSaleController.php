<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\AuditService;
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

        // Riwayat entri back-date: bisa disaring per bulan & ada totalnya, supaya
        // bisa dicocokkan langsung dengan angka bulanan di Excel.
        $entries = PurchaseOrder::whereNotNull('order_date')->with('user');

        $bulan = $request->query('entri_bulan');
        if ($bulan && preg_match('/^\d{4}-\d{2}$/', $bulan)) {
            try {
                $m = Carbon::createFromFormat('Y-m-d', $bulan.'-01');
                $entries->whereBetween('order_date', [
                    $m->copy()->startOfMonth()->toDateString(),
                    $m->copy()->endOfMonth()->toDateString(),
                ]);
            } catch (\Throwable $e) {
                $bulan = null;   // input ngawur → tampilkan semua, bukan error
            }
        } else {
            $bulan = null;
        }

        return view('purchase_orders.backdated', [
            'partners' => User::whereIn('role', [User::ROLE_DISTRIBUTOR, User::ROLE_RESELLER])
                ->where('status', User::STATUS_ACTIVE)->orderBy('fullname')->get(),
            // Kedua harga tier dikirim: harga isi-otomatis mengikuti mitra terpilih.
            'products' => Product::where('status', Product::STATUS_ACTIVE)->orderBy('name')
                ->get(['id', 'name', 'sku', 'price_distributor', 'price_reseller']),
            'cutoff' => $this->service->stockCutoff(),
            'entriBulan' => $bulan,
            'entriTotal' => (float) (clone $entries)->sum('total_amount'),
            'entriCount' => (clone $entries)->count(),
            'recent' => $entries->orderByDesc('order_date')->orderByDesc('id')->paginate(25)->withQueryString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canDo('manage_hq_stock'), 403);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            // Penjualan tak mungkin terjadi di masa depan. Batas bawah menangkap
            // salah ketik tahun (mis. 2020 ketika maksudnya 2026) — pernah terjadi
            // dan baru ketahuan lewat grafik tren.
            'order_date' => ['required', 'date', 'before_or_equal:today', 'after_or_equal:2024-01-01'],
            // Pembeli sekali-beli: cukup namanya, tak perlu dibuatkan akun.
            'buyer_name' => ['nullable', 'string', 'max:150'],
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
                buyerName: $data['buyer_name'] ?? null,
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

    /**
     * Perbaiki tanggal order sebuah entri back-date (salah ketik tahun itu nyata).
     *
     * Sengaja TIDAK mengizinkan perpindahan melintasi batas potong stok: entri
     * pra-batas tak memotong stok, entri pasca-batas memotong. Memindahkannya
     * diam-diam akan membuat stok tak sinkron tanpa jejak. Untuk kasus itu,
     * hapus lalu catat ulang — eksplisit dan aman.
     */
    public function updateDate(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        abort_unless($request->user()->canDo('manage_hq_stock'), 403);
        abort_unless($purchaseOrder->order_date !== null, 404, 'Bukan entri back-date.');

        $data = $request->validate([
            'order_date' => ['required', 'date', 'before_or_equal:today', 'after_or_equal:2024-01-01'],
        ]);

        $new = Carbon::parse($data['order_date']);
        $cut = $this->service->stockCutoff();

        if ($cut) {
            $wasBefore = $purchaseOrder->orderDate()->lt($cut);
            $willBefore = $new->lt($cut);
            if ($wasBefore !== $willBefore) {
                return back()->with('error',
                    'Tanggal baru melewati batas potong stok ('.$cut->format('d M Y').'), '
                    .'sehingga status potong stoknya ikut berubah. Hapus PO ini lalu catat ulang '
                    .'supaya stoknya benar.');
            }
        }

        $old = $purchaseOrder->orderDate()->toDateString();
        $purchaseOrder->update(['order_date' => $new->toDateString()]);

        AuditService::log(
            action: 'fix_backdated_order_date',
            targetType: 'purchase_order',
            targetId: $purchaseOrder->id,
            before: ['order_date' => $old],
            after: ['order_date' => $new->toDateString()],
        );

        return back()->with('status',
            "Tanggal {$purchaseOrder->po_number} diperbaiki: {$old} → {$new->format('d M Y')}.");
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
