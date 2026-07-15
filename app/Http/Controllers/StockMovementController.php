<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\Request;

class StockMovementController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $filters = $request->only(['type', 'q', 'product_id', 'from', 'to']);

        $movements = StockMovement::query()
            ->with('product', 'user')
            ->when($user->isPartner(), fn ($q) => $q->where('user_id', $user->id))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('movement_type', $t))
            // Drill-down dari Laporan Stok HQ: 1 produk + rentang periode (HQ saja).
            ->when($filters['product_id'] ?? null, fn ($q, $id) => $q->where('product_id', $id)->whereNull('user_id'))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->whereHas('product', fn ($p) => $p->where('name', 'like', "%{$term}%")
                    ->orWhere('sku', 'like', "%{$term}%"));
            })
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        $focusProduct = ($filters['product_id'] ?? null)
            ? Product::find($filters['product_id'])
            : null;

        return view('stock_movements.index', [
            'movements' => $movements,
            'filters' => $filters,
            'types' => StockMovement::TYPES,
            'focusProduct' => $focusProduct,
        ]);
    }
}
