<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\RoiItem;
use App\Models\RoiSetting;
use App\Services\RoiCalculatorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'admin_pct' => ['required', 'numeric', 'min:0'],
            'voucher_pct' => ['required', 'numeric', 'min:0'],
            'komisi_pct' => ['required', 'numeric', 'min:0'],
            'komisi_cap' => ['required', 'integer', 'min:0'],
            'mall_pct' => ['required', 'numeric', 'min:0'],
            'pajak_pct' => ['required', 'numeric', 'min:0'],
            'operasional_pct' => ['required', 'numeric', 'min:0'],
            'affiliate_pct' => ['required', 'numeric', 'min:0'],
            'packing_default' => ['required', 'integer', 'min:0'],
            'proses_order_default' => ['required', 'integer', 'min:0'],
        ]);

        RoiSetting::current()->update($data);

        return back()->with('status', 'Setelan biaya global disimpan.');
    }

    public function storeItem(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id', Rule::unique('roi_items', 'product_id')],
            'selling_price' => ['required', 'integer', 'min:0'],
        ]);

        RoiItem::create($data); // modal/override tetap null = warisi COGS/global

        return back()->with('status', 'Produk ditambahkan ke kalkulator.');
    }

    public function updateItem(Request $request, RoiItem $item): RedirectResponse
    {
        $data = $request->validate([
            'selling_price' => ['required', 'integer', 'min:0'],
            'modal' => ['nullable', 'integer', 'min:0'],
            'packing' => ['nullable', 'integer', 'min:0'],
            'proses_order' => ['nullable', 'integer', 'min:0'],
            'admin_pct' => ['nullable', 'numeric', 'min:0'],
            'voucher_pct' => ['nullable', 'numeric', 'min:0'],
            'komisi_pct' => ['nullable', 'numeric', 'min:0'],
            'komisi_cap' => ['nullable', 'integer', 'min:0'],
            'mall_pct' => ['nullable', 'numeric', 'min:0'],
            'pajak_pct' => ['nullable', 'numeric', 'min:0'],
            'operasional_pct' => ['nullable', 'numeric', 'min:0'],
            'affiliate_pct' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Field kosong tiba sbg null (ConvertEmptyStringsToNull) -> warisi COGS/global.
        $item->update($data);

        return back()->with('status', "Baris {$item->product?->name} diperbarui.");
    }

    public function deleteItem(RoiItem $item): RedirectResponse
    {
        $item->delete();

        return back()->with('status', 'Baris dihapus.');
    }
}
