<?php

namespace App\Http\Controllers;

use App\Models\PartnerSale;
use App\Models\Product;
use App\Services\PartnerSaleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Penjualan mitra ke customer akhir (barang keluar). Hidup di bawah menu Stok —
 * bukan menu sidebar baru. Penjualnya SELALU mitra yang sedang login (staf
 * memakainya lewat "Masuk sebagai").
 */
class PartnerSaleController extends Controller
{
    public function __construct(private PartnerSaleService $sales) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->isPartner(), 403, 'Hanya mitra yang mencatat penjualan ke customer.');

        // Produk aktif untuk pemilih searchable + harga default (retail: mitra
        // menjual ke customer akhir).
        $products = Product::query()
            ->where('status', Product::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'price_retail', 'price_reseller', 'price_distributor']);

        // ?bulan=YYYY-MM menyaring riwayat; kosong = semua.
        $bulan = $this->parseMonth($request->query('bulan'));

        $recentQuery = PartnerSale::query()
            ->with('items')
            ->where('user_id', $user->id)
            ->when($bulan, fn ($q) => $q->whereBetween('sold_at', [
                $bulan->copy()->startOfMonth()->toDateString(),
                $bulan->copy()->endOfMonth()->toDateString(),
            ]))
            ->orderByDesc('sold_at')
            ->orderByDesc('id');

        $total = (clone $recentQuery)->sum('total_amount');
        $recent = $recentQuery->paginate(15)->withQueryString();

        return view('inventory.sales', [
            'user' => $user,
            'products' => $products,
            'recent' => $recent,
            'total' => $total,
            'bulan' => $bulan,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isPartner(), 403, 'Hanya mitra yang mencatat penjualan ke customer.');

        $data = $request->validate([
            'customer_name' => ['nullable', 'string', 'max:150'],
            'sold_at' => ['required', 'date', 'before_or_equal:today', 'after_or_equal:2024-01-01'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.qty' => ['nullable', 'integer', 'min:0'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $sale = $this->sales->record(
                seller: $user,
                customerName: $data['customer_name'] ?? null,
                lines: $data['items'],
                notes: $data['notes'] ?? null,
                creatorId: $user->id,
                soldAt: Carbon::parse($data['sold_at']),
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            // Stok tak cukup → seluruh nota batal. Pesannya sudah manusiawi.
            return back()->withInput()->withErrors(['items' => $e->getMessage()]);
        }

        return redirect()->route('partner-sales.index')
            ->with('status', "Penjualan {$sale->sale_number} tercatat — total Rp ".number_format((float) $sale->total_amount, 0, ',', '.').'. Stok sudah dipotong.');
    }

    /** ?bulan=YYYY-MM → Carbon, atau null. */
    private function parseMonth(?string $v): ?Carbon
    {
        if (! $v || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v)) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d', $v.'-01')->startOfMonth();
    }
}
