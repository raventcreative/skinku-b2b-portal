<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\ShopeeConnection;
use App\Models\TiktokConnection;
use App\Services\ImageService;
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
    /** Petunjuk flash `error` saat push GAGAL — dipilih pemanggil pushFlash. Default = jalur stok/harga. */
    private const FAIL_HINT_STOK_HARGA = 'stok/harga belum masuk. Buka Stok TikTok / Stok Shopee untuk lihat pesan error tiap listing (sering: scope Product app belum di-otorisasi ulang).';

    private const FAIL_HINT_KONTEN = 'konten belum masuk. Buka Stok TikTok / Stok Shopee untuk lihat pesan error tiap listing.';

    public function index(Request $request): View
    {
        $tab = in_array($request->query('tab'), ['satuan', 'bundle'], true) ? $request->query('tab') : 'semua';

        $q = MarketplaceMaster::with('listings:id,master_id,channel');
        if ($tab === 'satuan') {
            $q->where('is_bundle', false);
        } elseif ($tab === 'bundle') {
            $q->where('is_bundle', true);
        }

        return view('marketplace-stock.index', [
            'tab' => $tab,
            'masters' => $q->orderBy('name')->get(),
            'counts' => [
                'semua' => MarketplaceMaster::count(),
                'satuan' => MarketplaceMaster::where('is_bundle', false)->count(),
                'bundle' => MarketplaceMaster::where('is_bundle', true)->count(),
            ],
            'unlinkedCount' => MarketplaceListing::whereNull('master_id')->count(),
            'allListings' => MarketplaceListing::orderBy('channel')->orderBy('seller_sku')->get(['id', 'channel', 'seller_sku', 'title', 'master_id']),
            'masterNames' => MarketplaceMaster::pluck('name', 'id'),
            'shopNames' => [
                'tiktok' => TiktokConnection::latest('id')->value('shop_name'),
                'shopee' => ShopeeConnection::latest('id')->value('shop_name'),
            ],
        ]);
    }

    public function create(): View
    {
        return view('marketplace-stock.form', ['master' => new MarketplaceMaster]);
    }

    public function store(Request $r, ImageService $img, MarketplaceMasterService $svc): RedirectResponse
    {
        $this->validateMaster($r);
        $master = MarketplaceMaster::create($this->masterAttributes($r));
        $this->applyMasterInputs($r, $master, $img, $svc);

        return redirect()->route('marketplace-stock.index')->with('status', "Produk master \"{$master->name}\" dibuat.");
    }

    public function edit(MarketplaceMaster $master): View
    {
        return view('marketplace-stock.form', ['master' => $master]);
    }

    public function update(Request $r, MarketplaceMaster $master, ImageService $img, MarketplaceMasterService $svc): RedirectResponse
    {
        $this->validateMaster($r);
        $master->update($this->masterAttributes($r));
        $this->applyMasterInputs($r, $master, $img, $svc);
        $svc->pushMaster($master);

        return redirect()->route('marketplace-stock.index')->with('status', "Produk master \"{$master->name}\" diperbarui.");
    }

    public function duplicate(MarketplaceMaster $master, MarketplaceMasterService $svc): RedirectResponse
    {
        $copy = $svc->duplicateMaster($master);

        return redirect()->route('marketplace-stock.edit', $copy)->with('status', "Digandakan dari \"{$master->name}\". Sesuaikan lalu simpan.");
    }

    private function validateMaster(Request $r): void
    {
        $r->validate([
            'name' => ['required', 'string', 'max:255'],
            'master_sku' => ['required', 'string', 'max:255'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'is_bundle' => ['nullable', 'boolean'],
            'category' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:8000'],
            'weight_g' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'length_cm' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'width_cm' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'height_cm' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'barcode' => ['nullable', 'string', 'max:255'],
            'foto' => ['nullable', 'array', 'max:9'],
            'foto.*' => ['image', 'max:5120'],
        ]);
    }

    /** Atribut master dari request (dipakai store & update). */
    private function masterAttributes(Request $r): array
    {
        return [
            'master_sku' => $r->master_sku,
            'name' => $r->name,
            'name_key' => MarketplaceMaster::normalizeName($r->name),
            'is_bundle' => $r->boolean('is_bundle'),
            'category' => $r->input('category'),
            'description' => $r->input('description'),
            'weight_g' => $r->filled('weight_g') ? (int) $r->weight_g : null,
            'length_cm' => $r->filled('length_cm') ? (int) $r->length_cm : null,
            'width_cm' => $r->filled('width_cm') ? (int) $r->width_cm : null,
            'height_cm' => $r->filled('height_cm') ? (int) $r->height_cm : null,
            'barcode' => $r->input('barcode'),
        ];
    }

    /** Set harga/stok (via setter supaya seeded_at ke-set) + attach foto (banyak, total maks 9) bila di-upload. */
    private function applyMasterInputs(Request $r, MarketplaceMaster $master, ImageService $img, MarketplaceMasterService $svc): void
    {
        if ($r->filled('price')) {
            $svc->setMasterPrice($master, (float) $r->price);
        }
        if ($r->filled('stock')) {
            $svc->setMasterStock($master, (int) $r->stock);
        }
        $existing = $master->files()->where('collection', MarketplaceMaster::MASTER_IMAGE)->count();
        foreach ((array) $r->file('foto', []) as $file) {
            if (! $file || $existing >= 9) {
                continue;
            }
            // Foto master ditujukan utk marketplace → simpan lebih besar & tajam (1600px, q85)
            // ketimbang default 1280/q80. Browser sudah mengecilkan sebelum upload (lihat form),
            // jadi ini praktis tanpa re-shrink berarti; JS-off tetap aman (server yang mengecilkan).
            $img->attach($master, $file, MarketplaceMaster::MASTER_IMAGE, 1600, 85);
            $existing++;
        }
    }

    public function deleteFoto(MarketplaceMaster $master, File $file): RedirectResponse
    {
        $this->assertFotoMilikMaster($master, $file);
        $file->delete(); // model File hapus file fisik via deleting-hook

        return back()->with('status', 'Foto dihapus.');
    }

    public function setFotoUtama(MarketplaceMaster $master, File $file): RedirectResponse
    {
        $this->assertFotoMilikMaster($master, $file);
        // Foto utama = sort_order paling kecil. Set file ini 0, sisanya digeser >=1.
        $file->update(['sort_order' => 0]);
        $others = $master->filesIn(MarketplaceMaster::MASTER_IMAGE)->where('id', '!=', $file->id)->get();
        $i = 1;
        foreach ($others as $o) {
            $o->update(['sort_order' => $i++]);
        }

        return back()->with('status', 'Foto utama diperbarui.');
    }

    /** Guard IDOR: file harus milik master ini DAN ada di koleksi master_image, kalau tidak 404. */
    private function assertFotoMilikMaster(MarketplaceMaster $master, File $file): void
    {
        abort_unless(
            $file->fileable_type === MarketplaceMaster::class
                && (int) $file->fileable_id === (int) $master->id
                && $file->collection === MarketplaceMaster::MASTER_IMAGE,
            404,
        );
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
        $r->validate(['quantity' => ['required', 'integer', 'min:0', 'max:2147483647']]);
        $svc->setMasterStock($master, (int) $r->quantity);

        return $this->pushFlash(back(), $svc->pushMaster($master), "Stok master \"{$master->name}\" disetel.");
    }

    public function setMasterPrice(Request $r, MarketplaceMaster $master, MarketplaceMasterService $svc): RedirectResponse
    {
        $r->validate(['price' => ['required', 'numeric', 'min:0', 'max:9999999999.99']]);
        $svc->setMasterPrice($master, (float) $r->price);

        return $this->pushFlash(back(), $svc->pushMaster($master), "Harga master \"{$master->name}\" disetel.");
    }

    public function setChannelStock(Request $r, string $channel, MarketplaceMaster $master, MarketplaceMasterService $svc): RedirectResponse
    {
        abort_unless(in_array($channel, ['tiktok', 'shopee'], true), 404);
        $r->validate(['quantity' => ['required', 'integer', 'min:0', 'max:2147483647']]);
        $svc->setChannelStock($master, $channel, (int) $r->quantity);
        $svc->pushMaster($master);

        return back()->with('status', "Stok {$channel} — {$master->name} disetel sendiri.");
    }

    public function setChannelPrice(Request $r, string $channel, MarketplaceMaster $master, MarketplaceMasterService $svc): RedirectResponse
    {
        abort_unless(in_array($channel, ['tiktok', 'shopee'], true), 404);
        $r->validate(['price' => ['required', 'numeric', 'min:0', 'max:9999999999.99']]);
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

    public function kaitkan(Request $r, MarketplaceMaster $master, MarketplaceMasterService $svc): RedirectResponse
    {
        $data = $r->validate([
            'listing_ids' => ['required', 'array', 'min:1'],
            'listing_ids.*' => ['integer', 'exists:marketplace_listings,id'],
        ]);
        $n = $svc->linkListings($master, $data['listing_ids']);

        return $this->pushFlash(back(), $svc->pushMaster($master), "$n listing ditautkan ke \"{$master->name}\".");
    }

    public function lepas(Request $r, MarketplaceMaster $master, MarketplaceMasterService $svc): RedirectResponse
    {
        $data = $r->validate([
            'listing_ids' => ['required', 'array', 'min:1'],
            'listing_ids.*' => ['integer', 'exists:marketplace_listings,id'],
        ]);
        $n = $svc->unlinkListings($master, $data['listing_ids']);

        return back()->with('status', "$n listing dilepas dari \"{$master->name}\".");
    }

    /** Tandai/lepas master sbg Bundle (paket berisi >1 produk, bukan satuan). */
    public function toggleBundle(MarketplaceMaster $master): RedirectResponse
    {
        $master->update(['is_bundle' => ! $master->is_bundle]);

        return back()->with('status', $master->is_bundle ? "\"{$master->name}\" ditandai Bundle." : "\"{$master->name}\" jadi Satuan.");
    }

    public function pushAll(MarketplaceMasterService $svc): RedirectResponse
    {
        return $this->pushFlash(back(), $svc->pushAll(), 'Sinkron semua.');
    }

    /**
     * Dorong KONTEN master (deskripsi/berat/dimensi) ke listing marketplace-nya yang sudah ber-item_id.
     * MANUAL (tombol + konfirmasi) — tak ikut cron/"Sinkron semua"; nama & foto tak didorong, field kosong
     * dilewati. Selalu kirim (force) karena tombolnya memang "kirim & timpa". Konten level-produk di
     * marketplace, jadi berlaku ke seluruh produk listing-nya, bukan per varian.
     */
    public function pushContent(MarketplaceMaster $master, MarketplaceMasterService $svc): RedirectResponse
    {
        $r = $svc->pushMasterContent($master);
        $listings = $r['pushed'] + $r['skipped'] + $r['failed'];

        // Jujur: kalau tak ada yang benar-benar dikirim, bilang kenapa (bukan status kosong/"berhasil").
        $prefix = match (true) {
            $listings === 0 => "Konten \"{$master->name}\" belum dikirim — belum ada listing marketplace tertaut (tautkan lewat \"Tambah ke Marketplace\")",
            $r['skipped'] === $listings => "Konten \"{$master->name}\" belum dikirim — deskripsi, berat, dan dimensi produk ini masih kosong",
            default => "Dorong konten \"{$master->name}\"",
        };

        return $this->pushFlash(back(), $r, $prefix, self::FAIL_HINT_KONTEN);
    }

    /**
     * Flash hasil push JUJUR: `status` berisi hitungan OK/dilewati/gagal (bukan
     * asal "disinkron"); kalau ADA yang gagal → flash `error` + arahkan ke halaman
     * Stok TikTok/Shopee untuk baca pesan error tiap listing.
     *
     * @param  array{pushed:int,skipped:int,failed:int}  $r
     * @param  string  $failHint  kelanjutan teks flash `error` ("N push ke marketplace GAGAL — …"); default = stok/harga
     */
    private function pushFlash(RedirectResponse $back, array $r, string $prefix, string $failHint = self::FAIL_HINT_STOK_HARGA): RedirectResponse
    {
        $counts = [];
        if (($r['pushed'] ?? 0) > 0) {
            $counts[] = "{$r['pushed']} sinkron OK";
        }
        if (($r['skipped'] ?? 0) > 0) {
            $counts[] = "{$r['skipped']} dilewati";
        }
        if (($r['failed'] ?? 0) > 0) {
            $counts[] = "{$r['failed']} GAGAL";
        }
        $back->with('status', trim($prefix.($counts !== [] ? ' ('.implode(', ', $counts).')' : '')));
        if (($r['failed'] ?? 0) > 0) {
            $back->with('error', "{$r['failed']} push ke marketplace GAGAL — {$failHint}");
        }

        return $back;
    }

    public function resolve(MarketplaceMasterService $svc): RedirectResponse
    {
        $notes = [];
        $errors = [];
        foreach (['tiktok', 'shopee'] as $channel) {
            try {
                $r = $svc->resolveListings($channel);
                $notes[] = ucfirst($channel).": {$r['found']} listing";
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

    public function deleteMaster(MarketplaceMaster $master): RedirectResponse
    {
        $name = $master->name;
        $master->delete();

        return back()->with('status', "Master \"{$name}\" dihapus.");
    }

    public function kosongkan(MarketplaceMasterService $svc): RedirectResponse
    {
        $n = $svc->deleteAllMasters();

        return back()->with('status', "$n master dihapus — katalog dikosongkan. Stok HQ tak terpengaruh.");
    }
}
