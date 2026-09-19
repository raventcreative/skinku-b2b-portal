<?php

namespace App\Http\Controllers;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceStock;
use App\Models\Product;
use App\Models\ShopeeSkuMap;
use App\Models\TiktokSkuMap;
use App\Services\MarketplaceStockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Task 10: halaman "Stok Marketplace" — tabel pool stok per produk (yang sudah
 * dipetakan ke SKU TikTok/Shopee) + aksi (set pool, sinkron, refresh listing,
 * seed awal dari TikTok). HQ (hq_stock) TIDAK disentuh sama sekali di sini.
 */
class MarketplaceStockController extends Controller
{
    public function index(MarketplaceStockService $svc): View
    {
        $productIds = TiktokSkuMap::distinct()->pluck('product_id')
            ->merge(ShopeeSkuMap::distinct()->pluck('product_id'))
            ->unique()->values();

        $products = Product::whereIn('id', $productIds)->orderBy('name')->get();
        $pools = MarketplaceStock::whereIn('product_id', $productIds)->get()->keyBy('product_id');

        $rows = $products->map(fn (Product $p) => [
            'product' => $p,
            'pool' => $pools[$p->id]->quantity ?? null,
            'tiktok' => $this->listingFor($svc, 'tiktok', $p),
            'shopee' => $this->listingFor($svc, 'shopee', $p),
        ]);

        return view('marketplace-stock.index', [
            'rows' => $rows,
            'unmapped' => MarketplaceListing::where('last_status', 'unmapped')->get(),
        ]);
    }

    public function setStock(Request $request, Product $product, MarketplaceStockService $svc): RedirectResponse
    {
        $request->validate(['quantity' => ['required', 'integer', 'min:0']]);

        $svc->setPool($product, (int) $request->quantity);
        $svc->pushProduct($product); // set manual -> langsung push ke tiap listing yg memuatnya

        return back()->with('status', "Stok {$product->name} disetel & disinkron.");
    }

    public function push(Product $product, MarketplaceStockService $svc): RedirectResponse
    {
        $svc->pushProduct($product);

        return back()->with('status', 'Disinkron.');
    }

    public function pushAll(MarketplaceStockService $svc): RedirectResponse
    {
        $r = $svc->pushAll();

        return back()->with('status', "Sinkron semua: {$r['pushed']} terkirim, {$r['failed']} gagal.");
    }

    public function resolve(MarketplaceStockService $svc): RedirectResponse
    {
        $svc->resolveListings('tiktok');
        $svc->resolveListings('shopee');

        return back()->with('status', 'Daftar listing diperbarui.');
    }

    public function seed(MarketplaceStockService $svc): RedirectResponse
    {
        $r = $svc->seedFromTiktok();

        return back()->with('status', "Seed dari TikTok: {$r['seeded']} produk.");
    }

    /**
     * Listing channel ini yang komponennya (bundle-aware) memuat $product, atau null
     * bila belum ada listing yang terpetakan ke produk ini pada channel tsb.
     */
    private function listingFor(MarketplaceStockService $svc, string $channel, Product $product): ?MarketplaceListing
    {
        foreach (MarketplaceListing::where('channel', $channel)->get() as $listing) {
            foreach ($svc->componentsFor($channel, $listing->seller_sku) as $component) {
                if ($component['product_id'] === $product->id) {
                    return $listing;
                }
            }
        }

        return null;
    }
}
