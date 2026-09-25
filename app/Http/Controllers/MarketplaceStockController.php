<?php

namespace App\Http\Controllers;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Services\MarketplaceMasterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Produk Master E-commerce: tiap unit (satuan/varian/bundle) = 1 master dgn
 * stok+harga sendiri, tertaut ke listing TikTok/Shopee. HQ TIDAK disentuh.
 */
class MarketplaceStockController extends Controller
{
    public function index(MarketplaceMasterService $svc): View
    {
        $masters = MarketplaceMaster::with(['channels', 'listings'])->orderBy('name')->get();
        $rows = $masters->map(fn (MarketplaceMaster $m) => [
            'master' => $m,
            'tiktok' => $this->channelSummary($svc, $m, 'tiktok'),
            'shopee' => $this->channelSummary($svc, $m, 'shopee'),
        ]);

        return view('marketplace-stock.index', [
            'rows' => $rows,
            'unmastered' => MarketplaceListing::whereNull('master_id')->get(),
            'masters' => $masters,
        ]);
    }

    public function channel(string $channel, MarketplaceMasterService $svc): View
    {
        abort_unless(in_array($channel, ['tiktok', 'shopee'], true), 404);

        $masterIds = MarketplaceListing::where('channel', $channel)->whereNotNull('master_id')->distinct()->pluck('master_id');
        $masters = MarketplaceMaster::with(['channels', 'listings'])->whereIn('id', $masterIds)->orderBy('name')->get();
        $rows = $masters->map(function (MarketplaceMaster $m) use ($svc, $channel) {
            $ch = $m->channels->firstWhere('channel', $channel);

            return [
                'master' => $m,
                'override_stock' => $ch?->stock,
                'override_price' => $ch?->price,
                'eff_stock' => $svc->effectiveStock($m, $channel),
                'eff_price' => $svc->effectivePrice($m, $channel),
                'listing' => $m->listings->firstWhere('channel', $channel),
            ];
        });

        return view('marketplace-stock.channel', [
            'channel' => $channel,
            'rows' => $rows,
            'unmastered' => MarketplaceListing::where('channel', $channel)->whereNull('master_id')->get(),
            'masters' => MarketplaceMaster::orderBy('name')->get(['id', 'master_sku', 'name']),
        ]);
    }

    public function setMasterStock(Request $r, MarketplaceMaster $master, MarketplaceMasterService $svc): RedirectResponse
    {
        $r->validate(['quantity' => ['required', 'integer', 'min:0']]);
        $svc->setMasterStock($master, (int) $r->quantity);
        $svc->pushMaster($master);

        return back()->with('status', "Stok master {$master->name} disetel & disinkron.");
    }

    public function setMasterPrice(Request $r, MarketplaceMaster $master, MarketplaceMasterService $svc): RedirectResponse
    {
        $r->validate(['price' => ['required', 'numeric', 'min:0']]);
        $svc->setMasterPrice($master, (float) $r->price);
        $svc->pushMaster($master);

        return back()->with('status', "Harga master {$master->name} disetel & disinkron.");
    }

    public function setChannelStock(Request $r, string $channel, MarketplaceMaster $master, MarketplaceMasterService $svc): RedirectResponse
    {
        abort_unless(in_array($channel, ['tiktok', 'shopee'], true), 404);
        $r->validate(['quantity' => ['required', 'integer', 'min:0']]);
        $svc->setChannelStock($master, $channel, (int) $r->quantity);
        $svc->pushMaster($master);

        return back()->with('status', "Stok {$channel} — {$master->name} disetel sendiri.");
    }

    public function setChannelPrice(Request $r, string $channel, MarketplaceMaster $master, MarketplaceMasterService $svc): RedirectResponse
    {
        abort_unless(in_array($channel, ['tiktok', 'shopee'], true), 404);
        $r->validate(['price' => ['required', 'numeric', 'min:0']]);
        $svc->setChannelPrice($master, $channel, (float) $r->price);
        $svc->pushMaster($master);

        return back()->with('status', "Harga {$channel} — {$master->name} disetel sendiri.");
    }

    public function ikutMaster(Request $r, string $channel, MarketplaceMaster $master, MarketplaceMasterService $svc): RedirectResponse
    {
        abort_unless(in_array($channel, ['tiktok', 'shopee'], true), 404);
        $data = $r->validate(['field' => ['required', 'in:stock,price']]);
        $svc->ikutMaster($master, $channel, $data['field']);
        $svc->pushMaster($master);

        return back()->with('status', "{$channel} — {$master->name} ({$data['field']}) kembali ikut Master.");
    }

    public function tautkan(Request $r, MarketplaceMasterService $svc): RedirectResponse
    {
        $data = $r->validate([
            'listing_id' => ['required', 'integer', 'exists:marketplace_listings,id'],
            'master_id' => ['nullable', 'integer', 'exists:marketplace_masters,id'],
            'new_sku' => ['nullable', 'string'],
            'new_name' => ['nullable', 'string'],
        ]);
        $listing = MarketplaceListing::findOrFail($data['listing_id']);
        $svc->tautkanListing($listing, $data['master_id'] ?? null, $data['new_sku'] ?? null, $data['new_name'] ?? null);

        return back()->with('status', "Listing {$listing->seller_sku} ditautkan.");
    }

    public function push(MarketplaceMaster $master, MarketplaceMasterService $svc): RedirectResponse
    {
        $svc->pushMaster($master);

        return back()->with('status', 'Disinkron.');
    }

    public function pushAll(MarketplaceMasterService $svc): RedirectResponse
    {
        $r = $svc->pushAll();

        return back()->with('status', "Sinkron semua: {$r['pushed']} terkirim, {$r['skipped']} dilewati, {$r['failed']} gagal.");
    }

    public function resolve(MarketplaceMasterService $svc): RedirectResponse
    {
        $notes = [];
        $errors = [];
        foreach (['tiktok', 'shopee'] as $channel) {
            try {
                $r = $svc->resolveListings($channel);
                $notes[] = ucfirst($channel).": {$r['found']} listing ({$r['mastered']} termaster)";
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

    public function seed(MarketplaceMasterService $svc): RedirectResponse
    {
        try {
            $r = $svc->seedFromTiktok();
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal tarik stok awal dari TikTok: '.$e->getMessage().' — cek izin/scope Product.');
        }

        return back()->with('status', "Tarik stok awal dari TikTok: {$r['seeded']} unit di-seed, {$r['skipped']} dilewati.");
    }

    /** Ringkasan per channel utk tabel Produk Master: efektif + penanda override + status kirim. */
    private function channelSummary(MarketplaceMasterService $svc, MarketplaceMaster $m, string $channel): array
    {
        $ch = $m->channels->firstWhere('channel', $channel);
        $listing = $m->listings->firstWhere('channel', $channel);

        return [
            'eff_stock' => $svc->effectiveStock($m, $channel),
            'eff_price' => $svc->effectivePrice($m, $channel),
            'override_stock' => $ch?->stock,
            'override_price' => $ch?->price,
            'listing' => $listing,
        ];
    }
}
