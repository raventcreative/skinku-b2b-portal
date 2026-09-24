<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\RoiItem;
use App\Models\RoiSetting;
use App\Services\RoiCalculatorService;
use Illuminate\View\View;

/**
 * Kalkulator ROI: tabel produk (tarik katalog) -> hitung biaya platform TikTok,
 * profit bersih, dan target ROI/ROAS iklan. Setelan % global + override per baris.
 */
class RoiCalculatorController extends Controller
{
    public function index(RoiCalculatorService $svc): View
    {
        $settings = RoiSetting::current();
        $items = RoiItem::with('product')->get();

        $rows = $items->map(fn (RoiItem $i) => $svc->rowFor($i, $settings))
            ->sortBy(fn ($r) => $r['product']?->name ?? '')
            ->values();

        $existingIds = $items->pluck('product_id')->all();
        $products = Product::whereNotIn('id', $existingIds)->orderBy('name')->get(['id', 'name', 'sku', 'cogs']);

        return view('roi-calculator.index', [
            'settings' => $settings,
            'rows' => $rows,
            'summary' => $svc->summary($rows),
            'products' => $products,
        ]);
    }
}
