<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockMovement;
use App\Services\AuditService;
use App\Services\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class StockOpnameController extends Controller
{
    public function __construct(private InventoryService $service) {}

    /** Form opname: semua produk + stok sistem sekarang sebagai ancang-ancang. */
    public function index(Request $request)
    {
        abort_unless($request->user()->canDo('manage_hq_stock'), 403);

        $products = Product::query()
            ->where('status', '!=', Product::STATUS_DELETED)
            ->orderBy('name')->get();

        return view('inventory.opname', compact('products'));
    }

    /**
     * Simpan opname: untuk tiap produk yang angka fisiknya beda dari sistem,
     * catat selisih sebagai Penyesuaian (reference 'opname') bertanggal pilihan,
     * dan sinkronkan hq_stock ke angka fisik. Inilah titik nol laporan.
     */
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canDo('manage_hq_stock'), 403);

        $data = $request->validate([
            'opname_date' => ['required', 'date'],
            'counts' => ['required', 'array'],
            'counts.*' => ['nullable', 'integer', 'min:0'],
        ]);

        // Jadikan saldo awal HARI itu → catat di akhir hari sebelumnya.
        $occurredAt = Carbon::parse($data['opname_date'])->startOfDay()->subSecond();

        $changed = 0;
        foreach (Product::whereIn('id', array_keys($data['counts']))->get() as $product) {
            $physical = $data['counts'][$product->id];
            if ($physical === null || $physical === '') {
                continue; // kosong = tidak dihitung, lewati
            }
            $delta = (int) $physical - (int) $product->hq_stock;
            if ($delta === 0) {
                continue; // sudah cocok
            }
            $this->service->adjustHqStock(
                product: $product,
                delta: $delta,
                movementType: StockMovement::TYPE_ADJUSTMENT,
                notes: 'Stok opname '.Carbon::parse($data['opname_date'])->format('d M Y'),
                referenceType: 'opname',
                occurredAt: $occurredAt,
            );
            $changed++;
        }

        AuditService::log(
            action: 'stock_opname',
            targetType: 'inventory',
            targetId: null,
            after: ['date' => $data['opname_date'], 'produk_disesuaikan' => $changed],
        );

        return redirect()->route('hq-stock.report', ['mode' => 'harian', 'date' => Carbon::parse($data['opname_date'])->format('Y-m-d')])
            ->with('status', "Opname tersimpan. {$changed} produk disesuaikan. Laporan stok HQ mulai dari tanggal ini.");
    }
}
