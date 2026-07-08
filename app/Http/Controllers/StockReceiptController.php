<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockReceipt;
use App\Services\AuditService;
use App\Services\StockReceiptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StockReceiptController extends Controller
{
    public function __construct(private StockReceiptService $service) {}

    public function index()
    {
        $receipts = StockReceipt::query()
            ->withCount('items')
            ->with('creator')
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate(20);

        return view('stock_receipts.index', compact('receipts'));
    }

    public function create()
    {
        $products = Product::query()
            ->where('status', '!=', Product::STATUS_DELETED)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'hq_stock', 'cogs']);

        return view('stock_receipts.create', compact('products'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_name' => ['nullable', 'string', 'max:150'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'received_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Keep only fully-filled rows.
        $lines = collect($data['items'])
            ->filter(fn ($row) => ! empty($row['product_id']) && ! empty($row['quantity']) && isset($row['unit_cost']) && $row['unit_cost'] !== '')
            ->map(fn ($row) => [
                'product_id' => (int) $row['product_id'],
                'quantity' => (int) $row['quantity'],
                'unit_cost' => (float) $row['unit_cost'],
            ])
            ->values()
            ->all();

        if (empty($lines)) {
            return back()->withErrors(['items' => 'Tambahkan minimal satu baris produk yang lengkap (produk, qty, dan harga beli).'])->withInput();
        }

        $receipt = $this->service->receive([
            'supplier_name' => $data['supplier_name'] ?? null,
            'reference_no' => $data['reference_no'] ?? null,
            'received_at' => $data['received_at'],
            'notes' => $data['notes'] ?? null,
        ], $lines);

        AuditService::log(
            action: 'receive_stock',
            targetType: 'stock_receipt',
            targetId: $receipt->id,
            after: ['receipt_number' => $receipt->receipt_number, 'total_cost' => $receipt->total_cost, 'lines' => count($lines)],
        );

        return redirect()->route('stock-receipts.show', $receipt)
            ->with('status', "Stok masuk {$receipt->receipt_number} tercatat. Stok pusat & HPP produk diperbarui.");
    }

    public function show(StockReceipt $stockReceipt)
    {
        $stockReceipt->load('items.product', 'creator');

        return view('stock_receipts.show', ['receipt' => $stockReceipt]);
    }
}
