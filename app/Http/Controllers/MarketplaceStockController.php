<?php

namespace App\Http\Controllers;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Services\ImageService;
use App\Services\MarketplaceMasterService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Produk Master E-commerce: tiap unit (satuan/varian/bundle) = 1 master dgn
 * stok+harga sendiri, tertaut ke listing TikTok/Shopee. HQ TIDAK disentuh.
 */
class MarketplaceStockController extends Controller
{
    public function index(Request $request, MarketplaceMasterService $svc): View
    {
        $tab = in_array($request->query('tab'), ['satuan', 'bundle'], true) ? $request->query('tab') : 'semua';

        $base = MarketplaceMaster::with(['channels', 'listings']);
        $q = (clone $base);
        if ($tab === 'satuan') {
            $q->where('is_bundle', false);
        } elseif ($tab === 'bundle') {
            $q->where('is_bundle', true);
        }
        $masters = $q->orderBy('name')->get();

        $rows = $masters->map(fn (MarketplaceMaster $m) => [
            'master' => $m,
            'tiktok' => $this->channelSummary($svc, $m, 'tiktok'),
            'shopee' => $this->channelSummary($svc, $m, 'shopee'),
        ]);

        return view('marketplace-stock.index', [
            'tab' => $tab,
            'rows' => $rows,
            'counts' => [
                'semua' => (clone $base)->count(),
                'satuan' => (clone $base)->where('is_bundle', false)->count(),
                'bundle' => (clone $base)->where('is_bundle', true)->count(),
            ],
            'unmasteredCount' => MarketplaceListing::whereNull('master_id')->count(),
            // Sama scope tab dgn baris tabel: cegah dropdown "Gabung ke master lain" di
            // tab Satuan/Bundle membocorkan nama master kategori lain ke HTML (row-nya
            // tersembunyi, tapi <option>-nya tetap kerender kalau tak di-scope) — tab
            // Semua tetap lihat semua master (minus dirinya sendiri, lihat @if di view).
            'allMasters' => $this->mastersForTab($tab)->orderBy('name')->get(['id', 'master_sku', 'name']),
        ]);
    }

    /** Query dasar (tanpa eager-load) untuk daftar master ter-filter tab yg sama dgn baris tabel. */
    private function mastersForTab(string $tab): Builder
    {
        $query = MarketplaceMaster::query();
        if ($tab === 'satuan') {
            $query->where('is_bundle', false);
        } elseif ($tab === 'bundle') {
            $query->where('is_bundle', true);
        }

        return $query;
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

    /** Tandai/lepas master sbg Bundle (paket berisi >1 produk, bukan satuan). */
    public function toggleBundle(MarketplaceMaster $master): RedirectResponse
    {
        $master->update(['is_bundle' => ! $master->is_bundle]);

        return back()->with('status', $master->is_bundle ? "\"{$master->name}\" ditandai Bundle." : "\"{$master->name}\" jadi Satuan.");
    }

    /** Gabung master $master (sumber) ke master lain (target): listing pindah, sumber dihapus. */
    public function gabung(Request $r, MarketplaceMaster $master, MarketplaceMasterService $svc): RedirectResponse
    {
        $data = $r->validate(['target_master_id' => ['required', 'integer', 'exists:marketplace_masters,id', 'different:'.$master->id]]);
        $svc->mergeMaster($master, MarketplaceMaster::findOrFail($data['target_master_id']));

        return back()->with('status', 'Master digabung.');
    }

    /** Upload/ganti foto master secara manual (menang atas image_url hasil resolve channel). */
    public function uploadFoto(Request $r, MarketplaceMaster $master, ImageService $img): RedirectResponse
    {
        $r->validate(['foto' => ['required', 'image', 'max:5120']]);
        $img->attach($master, $r->file('foto'), MarketplaceMaster::MASTER_IMAGE);

        return back()->with('status', "Foto \"{$master->name}\" diperbarui.");
    }

    /** Jadikan master otomatis SEMUA listing yang belum termaster (dari data listing, tanpa API). */
    public function masterizeAll(MarketplaceMasterService $svc): RedirectResponse
    {
        $n = $svc->masterizeUnmastered();

        return back()->with('status', "$n listing dijadikan Produk Master otomatis. Isi stok/harga di tabel Master, lalu Sinkron.");
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

    /** Aksi "Siapkan Master": resolve tiktok+shopee lalu bersihkan master orphan. */
    public function siapkan(MarketplaceMasterService $svc): RedirectResponse
    {
        $r = $svc->siapkanMaster();
        $msg = "Siapkan master: {$r['found']} listing diproses, {$r['orphan_deleted']} master kosong dibersihkan.";
        $redirect = back()->with('status', $msg);
        if ($r['errors']) {
            $redirect->with('error', implode(' · ', $r['errors']).' — cek izin/scope Product di channel.');
        }

        return $redirect;
    }

    public function deleteMaster(MarketplaceMaster $master): RedirectResponse
    {
        $name = $master->name;
        $master->delete();

        return back()->with('status', "Master \"{$name}\" dihapus.");
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
