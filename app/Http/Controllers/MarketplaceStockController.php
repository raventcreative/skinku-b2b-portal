<?php

namespace App\Http\Controllers;

use App\Models\MarketplaceChannelOverride;
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
        // Override per (product,channel) — dipakai buat penanda "Override: N" di kolom TikTok/Shopee.
        $overrides = MarketplaceChannelOverride::whereIn('product_id', $productIds)->get()->groupBy('product_id');

        $rows = $products->map(fn (Product $p) => [
            'product' => $p,
            'pool' => $pools[$p->id]->quantity ?? null,
            'tiktok' => $this->listingFor($svc, 'tiktok', $p),
            'shopee' => $this->listingFor($svc, 'shopee', $p),
            'tiktok_override' => $overrides->get($p->id)?->firstWhere('channel', 'tiktok')?->quantity,
            'shopee_override' => $overrides->get($p->id)?->firstWhere('channel', 'shopee')?->quantity,
        ]);

        return view('marketplace-stock.index', [
            'rows' => $rows,
            'unmapped' => MarketplaceListing::where('last_status', 'unmapped')->get(),
            'products' => Product::orderBy('name')->get(['id', 'name', 'sku']),
        ]);
    }

    /**
     * Halaman per-channel (Fase 1.5): mirip index() tapi hanya produk yang
     * dipetakan pada channel INI, dengan override channel-nya sendiri (bukan
     * pool Master) sebagai fokus utama — "Ikut Master" vs "Override: N".
     */
    public function channel(string $channel, MarketplaceStockService $svc): View
    {
        abort_unless(in_array($channel, ['tiktok', 'shopee'], true), 404);

        $mapModel = $channel === 'tiktok' ? TiktokSkuMap::class : ShopeeSkuMap::class;
        $productIds = $mapModel::distinct()->pluck('product_id')->unique()->values();
        $products = Product::whereIn('id', $productIds)->orderBy('name')->get();
        $overrides = MarketplaceChannelOverride::where('channel', $channel)->whereIn('product_id', $productIds)->get()->keyBy('product_id');

        $rows = $products->map(fn (Product $p) => [
            'product' => $p,
            'override' => $overrides->has($p->id) ? (int) $overrides[$p->id]->quantity : null,
            'effective' => $svc->channelStock($p->id, $channel),
            'listing' => $this->listingFor($svc, $channel, $p),
        ]);

        return view('marketplace-stock.channel', [
            'channel' => $channel,
            'rows' => $rows,
            'unmapped' => MarketplaceListing::where('channel', $channel)->where('last_status', 'unmapped')->get(),
            'products' => Product::orderBy('name')->get(['id', 'name', 'sku']),
        ]);
    }

    /** Set override channel ini ke nilai absolut lalu langsung sinkron (Master TIDAK disentuh). */
    public function setOverride(Request $r, string $channel, Product $product, MarketplaceStockService $svc): RedirectResponse
    {
        abort_unless(in_array($channel, ['tiktok', 'shopee'], true), 404);
        $r->validate(['quantity' => ['required', 'integer', 'min:0']]);

        $svc->setChannelOverride($product, $channel, (int) $r->quantity);
        $svc->pushProduct($product);

        return back()->with('status', "Stok {$channel} — {$product->name} disetel sendiri.");
    }

    /** Hapus override channel ini — stok efektifnya jatuh balik ke pool Master. */
    public function ikutMaster(string $channel, Product $product, MarketplaceStockService $svc): RedirectResponse
    {
        abort_unless(in_array($channel, ['tiktok', 'shopee'], true), 404);

        $svc->clearChannelOverride($product, $channel);
        $svc->pushProduct($product);

        return back()->with('status', "Stok {$channel} — {$product->name} kembali ikut Master.");
    }

    /** Tautkan seller_sku (listing belum terpetakan) ke produk master langsung dari halaman Stok. */
    public function tautkan(Request $request, string $channel, MarketplaceStockService $svc): RedirectResponse
    {
        abort_unless(in_array($channel, ['tiktok', 'shopee'], true), 404);
        $data = $request->validate([
            'seller_sku' => ['required', 'string'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'qty' => ['required', 'integer', 'min:1'],
        ]);

        $svc->linkSku($channel, $data['seller_sku'], (int) $data['product_id'], (int) $data['qty']);

        return back()->with('status', "SKU {$data['seller_sku']} ditautkan ke produk.");
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
        // Per-channel: satu channel gagal (mis. scope Product belum aktif) tak
        // menggagalkan yang lain, dan tampil pesan jelas — bukan halaman 500.
        $notes = [];
        $errors = [];
        foreach (['tiktok', 'shopee'] as $channel) {
            try {
                $r = $svc->resolveListings($channel);
                $notes[] = ucfirst($channel).": {$r['found']} listing ({$r['mapped']} terpetakan, {$r['unmapped']} belum)";
            } catch (\Throwable $e) {
                $errors[] = ucfirst($channel).' gagal: '.$e->getMessage();
            }
        }

        $redirect = back();
        if ($notes) {
            $redirect->with('status', 'Refresh listing — '.implode(' · ', $notes));
        }
        if ($errors) {
            $redirect->with('error', implode(' · ', $errors).' — cek izin/scope Product di app channel.');
        }

        return $redirect;
    }

    public function seed(MarketplaceStockService $svc): RedirectResponse
    {
        try {
            $r = $svc->seedFromTiktok();
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal tarik stok awal dari TikTok: '.$e->getMessage().' — cek izin/scope Product.');
        }

        return back()->with('status', "Tarik stok awal dari TikTok: {$r['seeded']} produk di-seed, {$r['skipped']} dilewati.");
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
