<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\Product;
use App\Models\ShopeeConnection;
use App\Models\TiktokConnection;
use App\Services\ImageService;
use App\Services\MarketplaceMasterService;
use App\Support\Rupiah;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Produk Master E-commerce: tiap unit (satuan/varian/bundle) = 1 master dgn
 * stok+harga sendiri, tertaut ke listing TikTok/Shopee. HQ TIDAK disentuh.
 */
class MarketplaceStockController extends Controller
{
    /** Petunjuk flash `error` saat push GAGAL — dipilih pemanggil pushFlash. Default = jalur stok/harga. */
    private const FAIL_HINT_STOK_HARGA = 'stok/harga belum masuk. Buka Stok TikTok / Stok Shopee untuk lihat pesan error tiap listing (sering: scope Product app belum di-otorisasi ulang).';

    private const FAIL_HINT_KONTEN = 'konten/foto belum masuk. Buka Stok TikTok / Stok Shopee untuk lihat pesan error tiap listing.';

    public function index(Request $request): View
    {
        $tab = in_array($request->query('tab'), ['satuan', 'bundle'], true) ? $request->query('tab') : 'semua';

        // Hanya master teratas (induk/tunggal); varian ditampilkan menempel di bawah induknya.
        $q = MarketplaceMaster::whereNull('parent_id')->with(['listings:id,master_id,channel', 'variants.listings:id,master_id,channel', 'bundleItems.component.channels', 'variants.bundleItems.component.channels']);
        if ($tab === 'satuan') {
            $q->where('is_bundle', false);
        } elseif ($tab === 'bundle') {
            $q->where('is_bundle', true);
        }

        $masters = $q->orderBy('name')->get();
        // Stok bundle ber-resep = hitungan dari komponen (stok dasar, tanpa override channel) utk ditampilkan.
        $svc = app(MarketplaceMasterService::class);
        // Induk + varian (varian bisa ber-isi, mis. "3 Pcs" = Scrub-1 × 3).
        $semua = $masters->concat($masters->flatMap(fn ($m) => $m->variants));
        $stokBundle = $semua->filter(fn ($m) => $m->bundleItems->isNotEmpty())->mapWithKeys(fn ($m) => [$m->id => $svc->effectiveStock($m, '')]);
        // Bundle yg stoknya tak bisa dihitung: sebut isi mana yg stoknya belum diisi.
        $isiKosong = $semua->filter(fn ($m) => $m->bundleItems->isNotEmpty() && $stokBundle[$m->id] === null)
            ->mapWithKeys(fn ($m) => [$m->id => $m->bundleItems->filter(fn ($it) => $it->component && $svc->effectiveStock($it->component, '') === null)->map(fn ($it) => $it->component->master_sku)->implode(', ')]);

        return view('marketplace-stock.index', [
            'tab' => $tab,
            'masters' => $masters,
            'stokBundle' => $stokBundle,
            'isiKosong' => $isiKosong,
            'counts' => [
                'semua' => MarketplaceMaster::whereNull('parent_id')->count(),
                'satuan' => MarketplaceMaster::whereNull('parent_id')->where('is_bundle', false)->count(),
                'bundle' => MarketplaceMaster::whereNull('parent_id')->where('is_bundle', true)->count(),
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
        return view('marketplace-stock.form', ['master' => new MarketplaceMaster, 'komponenOpsi' => $this->komponenOpsi(null), 'produkHq' => $this->produkHq()]);
    }

    public function store(Request $r, ImageService $img, MarketplaceMasterService $svc): RedirectResponse
    {
        $this->normalisasiHarga($r);
        $this->validateMaster($r);
        $master = MarketplaceMaster::create($this->masterAttributes($r) + $this->kategoriAttributes($r));
        $this->applyMasterInputs($r, $master, $img, $svc);
        $this->simpanVarian($r, $master, $svc);
        $this->simpanIsiBundle($r, $master);

        return redirect()->route('marketplace-stock.index')->with('status', "Produk master \"{$master->name}\" dibuat.");
    }

    public function edit(MarketplaceMaster $master): View|RedirectResponse
    {
        // Varian diedit dari form induknya (konten level produk ada di induk).
        if ($master->parent_id) {
            return redirect()->route('marketplace-stock.edit', $master->parent_id);
        }

        return view('marketplace-stock.form', ['master' => $master->load('variants.listings', 'bundleItems'), 'komponenOpsi' => $this->komponenOpsi($master), 'produkHq' => $this->produkHq()]);
    }

    public function update(Request $r, MarketplaceMaster $master, ImageService $img, MarketplaceMasterService $svc): RedirectResponse
    {
        $this->normalisasiHarga($r);
        $this->validateMaster($r);
        $master->update($this->masterAttributes($r) + $this->kategoriAttributes($r));
        $this->applyMasterInputs($r, $master, $img, $svc);
        $pindah = $this->simpanVarian($r, $master, $svc);
        $this->simpanIsiBundle($r, $master->fresh());
        $this->pushKeluarga($master, $svc, true);
        $svc->pushBundleTerkait($master);
        // Sinkron otomatis nama/deskripsi/berat/dimensi/barcode/foto — hanya listing yg sudah pernah didorong manual,
        // hanya yg berubah. Upload foto bisa lama → beri waktu (lihat pushContent()).
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }
        $auto = $svc->pushMasterContent($master, false, true);

        $back = redirect()->route('marketplace-stock.index');
        $info = $auto['pushed'] > 0 ? " Konten & foto ikut tersinkron ke {$auto['pushed']} listing marketplace." : '';
        if ($auto['failed'] > 0) {
            $back->with('error', "{$auto['failed']} sinkron konten/foto ke marketplace GAGAL — ".self::FAIL_HINT_KONTEN);
        }

        if ($pindah > 0) {
            $info .= " {$pindah} listing yang tadinya tertaut ke produk ini dipindah ke varian pertama — cek & tautkan ulang per varian bila perlu.";
        }

        return $back->with('status', "Produk master \"{$master->name}\" diperbarui.{$info}");
    }

    public function duplicate(MarketplaceMaster $master, MarketplaceMasterService $svc): RedirectResponse
    {
        $copy = $svc->duplicateMaster($master);

        return redirect()->route('marketplace-stock.edit', $copy)->with('status', "Digandakan dari \"{$master->name}\". Sesuaikan lalu simpan.");
    }

    /**
     * Harga dari input bertitik ribuan ("195.000") → angka polos SEBELUM validasi (Rupiah::polos), termasuk harga
     * tiap varian. Normalnya JS form sudah mengirim angka polos (partials/rupiah-input); ini jaring pengaman utk
     * JS mati/browser lama — "65.000" lolos `numeric` sbg 65 kalau tak dinormalkan.
     */
    private function normalisasiHarga(Request $r): void
    {
        if ($r->has('price')) {
            $r->merge(['price' => Rupiah::polos($r->input('price'))]);
        }
        $varian = $r->input('varian');
        if (is_array($varian)) {
            $r->merge(['varian' => array_map(
                fn ($row) => is_array($row) && array_key_exists('price', $row) ? ['price' => Rupiah::polos($row['price'])] + $row : $row,
                $varian,
            )]);
        }
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
            'urutan_foto' => ['nullable', 'string', 'max:200'],
            'variant_type' => ['nullable', 'string', 'max:50'],
            'varian' => ['nullable', 'array', 'max:50'],
            'varian.*.id' => ['nullable', 'integer'],
            'varian.*.name' => ['required', 'string', 'max:100'],
            'varian.*.sku' => ['required', 'string', 'max:255'],
            'varian.*.price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'varian.*.stock' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'varian.*.barcode' => ['nullable', 'string', 'max:255'],
            // Isi varian (mis. "3 Pcs" = SKU Scrub-1 × 3) → stok varian dihitung otomatis.
            'varian.*.isi_sku' => ['nullable', 'string', 'max:255'],
            'varian.*.isi_qty' => ['nullable', 'integer', 'min:1', 'max:999'],
            'isi_bundle' => ['nullable', 'array', 'max:20'],
            'isi_bundle.*.component_id' => ['required', 'integer', 'exists:marketplace_masters,id'],
            'isi_bundle.*.qty' => ['required', 'integer', 'min:1', 'max:999'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'tiktok_category_id' => ['nullable', 'regex:/^\d{1,32}$/'],
            'tiktok_category_name' => ['nullable', 'string', 'max:500'],
            'tiktok_attributes' => ['nullable', 'string', 'max:60000'],
            'shopee_category_id' => ['nullable', 'regex:/^\d{1,32}$/'],
            'shopee_category_name' => ['nullable', 'string', 'max:500'],
            'shopee_attributes' => ['nullable', 'string', 'max:60000'],
            'shopee_brand' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /**
     * Kolom kategori marketplace dari form — HANYA bila field dikirim (form lain/tes lama tak menyentuhnya).
     * Atribut = JSON dari skrip pemilih; disaring ketat ke bentuk [{id, values:[{id, name, unit?}]}].
     */
    private function kategoriAttributes(Request $r): array
    {
        $out = [];
        // "Produk HQ" = PENANDA master ini = produk HQ mana (dipakai mencocokkan resep HQ; stok BELUM disambung).
        if ($r->has('product_id')) {
            $out['product_id'] = $r->input('product_id') ?: null;
        }
        foreach (['tiktok', 'shopee'] as $ch) {
            if (! $r->has("{$ch}_category_id")) {
                continue;
            }
            $id = $r->input("{$ch}_category_id") ?: null;
            $out["{$ch}_category_id"] = $id;
            $out["{$ch}_category_name"] = $id ? $r->input("{$ch}_category_name") : null;
            $out["{$ch}_attributes"] = $id ? self::saringAtribut((string) $r->input("{$ch}_attributes")) : null;
        }
        if ($r->has('shopee_brand')) {
            $b = json_decode((string) $r->input('shopee_brand'), true);
            $out['shopee_brand'] = ($out['shopee_category_id'] ?? true) && is_array($b) && isset($b['brand_id']) && is_numeric($b['brand_id'])
                ? ['brand_id' => (int) $b['brand_id'], 'original_brand_name' => mb_substr((string) ($b['original_brand_name'] ?? ''), 0, 255)]
                : null;
        }

        return $out;
    }

    /** @return list<array{id:string, values:list<array>}> */
    private static function saringAtribut(string $json): array
    {
        $rows = json_decode($json, true);
        if (! is_array($rows)) {
            return [];
        }
        $out = [];
        foreach (array_slice($rows, 0, 200) as $a) {
            if (! is_array($a) || ! preg_match('/^\d{1,32}$/', (string) ($a['id'] ?? ''))) {
                continue;
            }
            $values = [];
            foreach (array_slice((array) ($a['values'] ?? []), 0, 50) as $v) {
                $vid = (string) ($v['id'] ?? '');
                $nama = trim(mb_substr((string) ($v['name'] ?? ''), 0, 255));
                if (($vid !== '' && ! preg_match('/^\d{1,32}$/', $vid)) || ($vid === '' && $nama === '')) {
                    continue;
                }
                $values[] = array_filter(['id' => $vid, 'name' => $nama, 'unit' => mb_substr((string) ($v['unit'] ?? ''), 0, 32)],
                    fn ($x, $k) => $k !== 'unit' || $x !== '', ARRAY_FILTER_USE_BOTH);
            }
            if ($values !== []) {
                $out[] = ['id' => (string) $a['id'], 'values' => $values];
            }
        }

        return $out;
    }

    /** JSON pemilih kategori: kategori daun channel yang cocok kata kunci. */
    public function cariKategori(Request $r, string $channel, MarketplaceMasterService $svc): JsonResponse
    {
        try {
            return response()->json(['data' => $svc->cariKategori($channel, (string) $r->query('q', ''))]);
        } catch (\Throwable $e) {
            return response()->json(['error' => MarketplaceMasterService::maskSecrets($e->getMessage())], 422);
        }
    }

    /** JSON usulan isi bundling dari resep HQ (SKU map) — hanya membaca. */
    public function resepHq(MarketplaceMaster $master, MarketplaceMasterService $svc): JsonResponse
    {
        return response()->json(['data' => $svc->resepDariHq($master)]);
    }

    /** JSON telusur bertingkat: anak langsung dari `parent` (default '0' = level teratas). */
    public function anakKategori(Request $r, string $channel, MarketplaceMasterService $svc): JsonResponse
    {
        $parent = (string) $r->query('parent', '0');
        abort_unless(preg_match('/^\d{1,32}$/', $parent), 404);
        try {
            return response()->json(['data' => $svc->anakKategori($channel, $parent)]);
        } catch (\Throwable $e) {
            return response()->json(['error' => MarketplaceMasterService::maskSecrets($e->getMessage())], 422);
        }
    }

    /** JSON atribut (+ merek Shopee) satu kategori daun. */
    public function atributKategori(string $channel, string $category, MarketplaceMasterService $svc): JsonResponse
    {
        abort_unless(preg_match('/^\d{1,32}$/', $category), 404);
        try {
            return response()->json(['data' => $svc->atributKategori($channel, $category)]);
        } catch (\Throwable $e) {
            return response()->json(['error' => MarketplaceMasterService::maskSecrets($e->getMessage())], 422);
        }
    }

    /** Tarik kategori + atribut yang sekarang terpasang di listing tertaut (titik awal sebelum diubah). */
    public function tarikKategori(MarketplaceMaster $master, MarketplaceMasterService $svc): RedirectResponse
    {
        $hasil = $svc->tarikKategori($master);
        if ($hasil === []) {
            return back()->with('error', 'Belum ada listing TikTok/Shopee tertaut — tautkan dulu lewat "Tambah ke Marketplace".');
        }
        $ok = array_keys(array_filter($hasil, fn ($x) => $x === 'ok'));
        $gagal = array_filter($hasil, fn ($x) => $x !== 'ok');
        $back = back();
        if ($ok !== []) {
            $back->with('status', 'Kategori & atribut ditarik dari '.implode(' & ', array_map('ucfirst', $ok)).'.');
        }
        if ($gagal !== []) {
            $back->with('error', collect($gagal)->map(fn ($m, $ch) => ucfirst($ch).': '.$m)->implode(' · '));
        }

        return $back;
    }

    /**
     * Simpan opsi varian (master anak) dari tabel Varian form — hanya bila kartu Varian ikut terkirim (`varian_ada`).
     * Daftar terkirim = himpunan lengkap: anak yang tak ada lagi dihapus (listingnya jadi tak tertaut). id hanya
     * dicocokkan ke anak master INI (guard IDOR). Harga/stok lewat setter hanya bila berubah (seeded_at tak tergeser).
     * Master tunggal yang baru diberi varian & sudah punya listing → listing dipindah ke varian pertama.
     *
     * @return int jumlah listing yang dipindah ke varian pertama
     */
    private function simpanVarian(Request $r, MarketplaceMaster $master, MarketplaceMasterService $svc): int
    {
        if (! $r->has('varian_ada') || $master->parent_id) {
            return 0;
        }
        $rows = array_values((array) $r->input('varian', []));
        $ada = $master->variants()->get()->keyBy('id');
        $sebelumnyaTunggal = $ada->isEmpty();
        $simpan = [];

        foreach ($rows as $row) {
            $nama = trim((string) $row['name']);
            $attrs = [
                'parent_id' => $master->id,
                'variant_name' => $nama,
                'master_sku' => trim((string) $row['sku']),
                'name' => $master->name.' - '.$nama,
                'name_key' => MarketplaceMaster::normalizeName($master->name.' - '.$nama),
                'barcode' => ($row['barcode'] ?? '') !== '' ? $row['barcode'] : null,
                'is_bundle' => $master->is_bundle,
            ];
            $anak = $ada->get((int) ($row['id'] ?? 0));
            if ($anak) {
                $anak->update($attrs);
            } else {
                $anak = MarketplaceMaster::create($attrs);
            }
            if (($row['price'] ?? '') !== '' && (float) $row['price'] !== (float) $anak->base_price) {
                $svc->setMasterPrice($anak, (float) $row['price']);
            }
            // Varian ber-isi: stok dihitung dari isinya → isian stok manual diabaikan.
            $punyaIsi = trim((string) ($row['isi_sku'] ?? '')) !== '';
            if (! $punyaIsi && ($row['stock'] ?? '') !== '' && (int) $row['stock'] !== $anak->base_stock) {
                $svc->setMasterStock($anak, (int) $row['stock']);
            }
            $simpan[] = $anak;
        }
        $this->simpanIsiVarian($rows, $simpan);

        $ids = array_map(fn ($a) => $a->id, $simpan);
        $ada->reject(fn ($a) => in_array($a->id, $ids, true))->each->delete();
        $master->update(['variant_type' => $simpan !== [] ? (trim((string) $r->input('variant_type')) ?: 'Varian') : null]);

        $pindah = 0;
        if ($sebelumnyaTunggal && $simpan !== []) {
            $pertama = $simpan[0]->fresh();
            $pindah = $master->listings()->update(['master_id' => $pertama->id]);
            if ($pindah > 0) {
                // Varian pertama mewarisi harga/stok induk bila belum diisi — supaya listing tak tiba-tiba kosong.
                if ($pertama->base_price === null && $master->base_price !== null) {
                    $svc->setMasterPrice($pertama, (float) $master->base_price);
                }
                if ($pertama->base_stock === null && $master->base_stock !== null) {
                    $svc->setMasterStock($pertama, (int) $master->base_stock);
                }
            }
        }

        return $pindah;
    }

    /**
     * Resep per varian (kolom "Isi"): SKU isi × qty, mis. varian "3 Pcs" = Scrub-1 × 3 → stok varian
     * = floor(stok Scrub-1 / 3) dan order varian memotong stok Scrub-1 ×3 (mesin bundle yang sama).
     * SKU dicari dulu di antara saudara varian (boleh yang baru dibuat di simpan yang sama), lalu
     * master lain yang bukan induk bervarian & bukan bundle ber-resep. Isi kosong → resep dihapus.
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<int,MarketplaceMaster>  $simpan  varian tersimpan, urutan sama dengan $rows
     */
    private function simpanIsiVarian(array $rows, array $simpan): void
    {
        foreach ($simpan as $i => $anak) {
            $sku = mb_strtolower(trim((string) ($rows[$i]['isi_sku'] ?? '')));
            $anak->bundleItems()->delete();
            if ($sku === '') {
                continue;
            }
            $komponen = collect($simpan)->first(fn ($s) => $s->id !== $anak->id && mb_strtolower((string) $s->master_sku) === $sku)
                ?? MarketplaceMaster::whereRaw('LOWER(master_sku) = ?', [$sku])->where('id', '!=', $anak->id)
                    ->whereDoesntHave('variants')->orderBy('id')->first();
            if ($komponen && ! $komponen->bundleItems()->exists()) {
                $anak->bundleItems()->create(['component_id' => $komponen->id, 'qty' => max(1, (int) ($rows[$i]['isi_qty'] ?? 1))]);
            }
        }
    }

    /**
     * Simpan resep bundling (kartu "Isi Bundling", flag `isi_bundle_ada`) sbg himpunan lengkap. Hanya utk master
     * bertipe Bundle; tipe Satuan → resep dikosongkan. Komponen dilewati bila: diri sendiri, induk bervarian (bukan unit
     * jual), atau bundle ber-resep (cegah bundle-dalam-bundle/siklus). Komponen sama dua kali → qty dijumlah.
     */
    private function simpanIsiBundle(Request $r, MarketplaceMaster $master): void
    {
        if (! $r->has('isi_bundle_ada')) {
            return;
        }
        $master->bundleItems()->delete();
        if (! $master->is_bundle) {
            return;
        }
        $qty = [];
        foreach ((array) $r->input('isi_bundle', []) as $row) {
            $qty[(int) $row['component_id']] = ($qty[(int) $row['component_id']] ?? 0) + (int) $row['qty'];
        }
        $sah = MarketplaceMaster::whereIn('id', array_keys($qty))->where('id', '!=', $master->id)
            ->whereDoesntHave('variants')->whereDoesntHave('bundleItems')->pluck('id');
        foreach ($sah as $id) {
            $master->bundleItems()->create(['component_id' => $id, 'qty' => min(999, $qty[$id])]);
        }
    }

    /** Daftar produk HQ utk penanda "Produk HQ" (hanya dibaca). */
    private function produkHq(): Collection
    {
        return Product::orderBy('name')->get(['id', 'name', 'sku']);
    }

    /** Pilihan komponen bundling: unit jual (bukan induk bervarian, bukan bundle ber-resep, bukan diri sendiri). */
    private function komponenOpsi(?MarketplaceMaster $kecuali): array
    {
        return MarketplaceMaster::whereDoesntHave('variants')->whereDoesntHave('bundleItems')
            ->when($kecuali, fn ($q) => $q->where('id', '!=', $kecuali->id))
            ->orderBy('name')->get(['id', 'name', 'master_sku', 'base_stock'])
            ->map(fn ($m) => ['id' => $m->id, 'sku' => $m->master_sku, 'label' => $m->name.' ('.$m->master_sku.')', 'stok' => $m->base_stock])->all();
    }

    /** Dorong stok & harga induk + semua variannya. @return array{pushed:int,skipped:int,failed:int} */
    private function pushKeluarga(MarketplaceMaster $master, MarketplaceMasterService $svc, bool $force): array
    {
        $tot = ['pushed' => 0, 'skipped' => 0, 'failed' => 0];
        foreach (MarketplaceMaster::whereIn('id', $master->keluargaIds())->get() as $m) {
            foreach ($svc->pushMaster($m, $force) as $k => $v) {
                $tot[$k] += $v;
            }
        }

        return $tot;
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
        $baru = []; // indeks foto[] → id File yang tercipta (utk urutan_foto)
        foreach ((array) $r->file('foto', []) as $i => $file) {
            if (! $file || $existing >= 9) {
                continue;
            }
            // Foto master ditujukan utk marketplace → simpan lebih besar & tajam (1600px, q85)
            // ketimbang default 1280/q80. Browser sudah mengecilkan sebelum upload (lihat form),
            // jadi ini praktis tanpa re-shrink berarti; JS-off tetap aman (server yang mengecilkan).
            $baru[(int) $i] = $img->attach($master, $file, MarketplaceMaster::MASTER_IMAGE, 1600, 85)->id;
            $existing++;
        }
        $this->terapkanUrutanFoto($master, (string) $r->input('urutan_foto', ''), $baru);
    }

    /**
     * Urutan foto campuran hasil geser SEBELUM Simpan: `urutan_foto` = token dipisah koma, `f<id>` = foto
     * tersimpan, `n<i>` = foto baru ke-i di `foto[]` (pertama = Utama). Longgar (bagian dari Simpan, bukan
     * AJAX): token asing/foto master lain/foto yang tak ter-upload diabaikan, foto yang tak disebut ditaruh
     * di belakang dengan urutan lamanya. Hanya foto milik master ini yang bisa disentuh (guard IDOR).
     */
    private function terapkanUrutanFoto(MarketplaceMaster $master, string $urutan, array $baru): void
    {
        if ($urutan === '') {
            return;
        }
        $milik = $master->filesIn(MarketplaceMaster::MASTER_IMAGE)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $ids = [];
        foreach (explode(',', $urutan) as $t) {
            if (! preg_match('/^([fn])(\d+)$/', trim($t), $m)) {
                continue;
            }
            $id = $m[1] === 'f' ? (int) $m[2] : ($baru[(int) $m[2]] ?? null);
            if ($id !== null && in_array($id, $milik, true) && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        $ids = array_merge($ids, array_values(array_diff($milik, $ids)));

        DB::transaction(function () use ($ids) {
            foreach ($ids as $i => $id) {
                File::whereKey($id)->update(['sort_order' => $i]);
            }
        });
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

    /**
     * Simpan urutan foto hasil geser (drag & drop, AJAX): `urutan` = id foto master_image dalam urutan baru
     * (pertama = Utama). WAJIB persis himpunan foto master ini — tak kurang/lebih/duplikat — sekaligus guard
     * IDOR (id milik master lain → himpunan tak cocok → 422) dan mencegah urutan tersimpan setengah. Urutan
     * baru mengubah photoHash, jadi "Dorong konten & foto" berikutnya mengirim foto dengan urutan ini.
     */
    public function urutkanFoto(Request $r, MarketplaceMaster $master): JsonResponse|RedirectResponse
    {
        // Validasi MANUAL, bukan $r->validate(): di app ini ValidationException pada rute web dirender sebagai
        // redirect 302 (juga utk request JSON) — fetch() mengikutinya ke halaman 200 sehingga kegagalan tampak
        // sukses. abort(422) selalu non-2xx. Duplikat/kurang/lebih/id asing tertangkap cek himpunan di bawah.
        $urutan = $r->input('urutan');
        abort_unless(is_array($urutan) && $urutan !== [] && count($urutan) <= 9, 422, 'Urutan foto tidak valid.');
        $ids = array_values(array_map('intval', $urutan));

        $milik = $master->filesIn(MarketplaceMaster::MASTER_IMAGE)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        abort_unless(collect($ids)->sort()->values()->all() === $milik, 422, 'Urutan foto tak cocok dengan foto produk ini — muat ulang halaman lalu coba lagi.');

        DB::transaction(function () use ($ids) {
            foreach ($ids as $i => $id) {
                File::whereKey($id)->update(['sort_order' => $i]);
            }
        });

        return $r->expectsJson()
            ? response()->json(['ok' => true, 'utama' => $ids[0]])
            : back()->with('status', 'Urutan foto disimpan.');
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
        if ($master->bundleItems()->exists()) {
            return back()->with('error', "Stok \"{$master->name}\" dihitung otomatis dari isi bundlingnya — ubah stok komponennya.");
        }
        $svc->setMasterStock($master, (int) $r->quantity);
        $hasil = $svc->pushMaster($master);
        $svc->pushBundleTerkait($master); // bundle yg memakai produk ini ikut ter-update

        return $this->pushFlash(back(), $hasil, "Stok master \"{$master->name}\" disetel.");
    }

    public function setMasterPrice(Request $r, MarketplaceMaster $master, MarketplaceMasterService $svc): RedirectResponse
    {
        $this->normalisasiHarga($r);
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
        $this->normalisasiHarga($r);
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
        if ($master->variants()->exists()) {
            return back()->with('error', "\"{$master->name}\" punya varian — tautkan listing ke tiap VARIAN (menu Atur di baris varian), bukan ke induknya.");
        }
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
     * Dorong KONTEN (deskripsi/berat/dimensi) + FOTO master ke listing marketplace-nya yang sudah ber-item_id.
     * MANUAL (tombol + konfirmasi) — tak ikut cron/"Sinkron semua"; nama tak didorong, field kosong dilewati.
     * Konten selalu dikirim (force, tombolnya "kirim & timpa"); foto lewat diff-guard — dorong PERTAMA ke tiap
     * listing mengganti SEMUA fotonya, setelah itu hanya bila set foto master berubah. Konten & foto level-produk
     * di marketplace, jadi berlaku ke seluruh produk listing-nya, bukan per varian.
     */
    public function pushContent(MarketplaceMaster $master, MarketplaceMasterService $svc): RedirectResponse
    {
        // Push pertama bisa upload s.d. 9 foto per channel secara berurutan — beri waktu lebih bila server
        // mengizinkan. function_exists: di PHP 8 fungsi yang dinonaktifkan hosting MELEMPAR Error (@ tak cukup).
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        $mulai = now()->startOfSecond();
        // Manual = SEMUA terdorong: stok & harga (paksa) + konten & foto (paksa, termasuk foto yg tak berubah).
        $stokHarga = $this->pushKeluarga($master, $svc, true);
        $r = $svc->pushMasterContent($master);
        $r['failed'] += $stokHarga['failed'];
        $listings = $r['pushed'] + $r['skipped'] + $r['failed'] - $stokHarga['failed'];
        // Transparan soal foto: listing yang fotonya diganti DI KLIK INI (sukses sejak awal request).
        $fotoDiganti = $master->listings()->where('last_photo_status', 'ok')->where('last_photo_pushed_at', '>=', $mulai)->count();

        // Jujur: kalau tak ada yang benar-benar dikirim, bilang kenapa (bukan status kosong/"berhasil").
        // Konten & foto dipaksa → "semua dilewati" = teks & foto master memang kosong.
        $prefix = match (true) {
            $listings === 0 => "Konten & foto \"{$master->name}\" belum dikirim — belum ada listing marketplace tertaut (tautkan lewat \"Tambah ke Marketplace\")",
            $r['skipped'] === $listings => "Konten & foto \"{$master->name}\" tak dikirim — nama/deskripsi/berat/dimensi/barcode kosong (atau produk bervarian) dan foto belum ada",
            default => "Dorong konten & foto \"{$master->name}\"".($fotoDiganti > 0 ? " — foto diganti di {$fotoDiganti} listing" : ''),
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
                // Timeout Guzzle menempel URL berisi access_token/sign — samarkan sebelum tampil di flash.
                $errors[] = ucfirst($channel).' gagal: '.MarketplaceMasterService::maskSecrets($e->getMessage());
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
        $dipakai = MarketplaceMaster::whereIn('id', $master->dipakaiBundle()->pluck('bundle_id'))->pluck('name');
        if ($dipakai->isNotEmpty()) {
            return back()->with('error', "\"{$master->name}\" masih jadi isi bundling: ".$dipakai->implode(', ').' — keluarkan dulu dari bundling itu.');
        }
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
