<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\AuditService;
use App\Services\HqStockReportService;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public const MAX_IMAGES = 8;

    public function __construct(private ImageService $images) {}

    public function index(Request $request, HqStockReportService $laporan)
    {
        $filters = $request->only(['q', 'status', 'category', 'stok']);

        $products = Product::query()
            ->when($filters['q'] ?? null, function ($query, $q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('name', 'like', "%{$q}%")
                        ->orWhere('sku', 'like', "%{$q}%")
                        ->orWhere('category', 'like', "%{$q}%");
                });
            })
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['category'] ?? null, fn ($query, $cat) => $query->where('category', $cat))
            ->when(($filters['stok'] ?? null) === 'menipis', fn ($query) => $query->stokPusatMenipis())
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $categories = Product::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category');
        // Saran Stok Min. dari barang keluar HQ; tombol "isi dari saran" hanya muncul bila ada yang kosong & bersaran.
        $saran = $laporan->saranStokMinimum();
        $kosongBersaran = $this->minimumKosong()->whereIn('id', array_keys($saran))->count();

        return view('products.index', compact('products', 'filters', 'categories', 'saran', 'kosongBersaran'));
    }

    /**
     * Isi Stok Min. dari saran utk produk aktif yang minimumnya masih kosong — angka yang sudah diisi tak ditimpa.
     * Tiap produk tercatat di Audit Log (aksi sama dgn isian manual, ditandai sumber "saran").
     */
    public function applyMinStockSuggestions(HqStockReportService $laporan): RedirectResponse
    {
        $saran = $laporan->saranStokMinimum();
        $produk = $this->minimumKosong()->whereIn('id', array_keys($saran))->get();
        foreach ($produk as $p) {
            $sebelum = $p->hq_min_stock;
            $p->update(['hq_min_stock' => $saran[$p->id]['saran']]);
            AuditService::log(action: 'update_product_min_stock', targetType: 'product', targetId: $p->id,
                before: ['hq_min_stock' => $sebelum], after: ['hq_min_stock' => $p->hq_min_stock, 'sumber' => 'saran']);
        }

        return back()->with('status', $produk->isEmpty()
            ? 'Tidak ada Stok Min. kosong yang punya saran.'
            : "Stok Min. diisi dari saran untuk {$produk->count()} produk.");
    }

    /** Produk aktif yang Stok Min.-nya belum diisi (kosong / 0 = tanpa pengingat). */
    private function minimumKosong()
    {
        return Product::where('status', Product::STATUS_ACTIVE)->where(fn ($q) => $q->whereNull('hq_min_stock')->orWhere('hq_min_stock', 0));
    }

    public function store(Request $request): RedirectResponse
    {
        // Tanpa izin Lihat HPP field HPP tak dikirim → produk baru mulai HPP 0 (diisi produksi/stok masuk/super admin).
        $data = $this->validateData($request) + ['cogs' => 0];

        $product = Product::create(Arr::except($data, ['images', 'remove_files']));

        foreach ($request->file('images', []) as $img) {
            if ($product->files()->where('collection', Product::GALLERY)->count() >= self::MAX_IMAGES) {
                break;
            }
            $this->images->attach($product, $img, Product::GALLERY);
        }

        AuditService::log(
            action: 'create_product',
            targetType: 'product',
            targetId: $product->id,
            after: $product->only(['name', 'sku', 'price_grand', 'price_distributor', 'price_reseller', 'price_retail', 'cogs', 'weight_grams', 'hq_stock', 'hq_min_stock', 'status']),
        );

        return back()->with('status', "Produk {$product->name} berhasil ditambahkan.");
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $this->validateData($request, $product);

        $before = $product->only(['name', 'sku', 'price_grand', 'price_distributor', 'price_reseller', 'price_retail', 'cogs', 'weight_grams', 'hq_stock', 'hq_min_stock', 'status']);

        $product->update(Arr::except($data, ['images', 'remove_files']));

        // Remove the photos the user unchecked (File row + physical file).
        $removeIds = $request->input('remove_files', []);
        if (! empty($removeIds)) {
            $product->files()->where('collection', Product::GALLERY)->whereIn('id', $removeIds)
                ->get()->each->delete();
        }

        // Append newly uploaded photos (capped at MAX_IMAGES total).
        foreach ($request->file('images', []) as $img) {
            if ($product->files()->where('collection', Product::GALLERY)->count() >= self::MAX_IMAGES) {
                break;
            }
            $this->images->attach($product, $img, Product::GALLERY);
        }

        AuditService::log(
            action: 'update_product',
            targetType: 'product',
            targetId: $product->id,
            before: $before,
            after: $product->only(['name', 'sku', 'price_grand', 'price_distributor', 'price_reseller', 'price_retail', 'cogs', 'weight_grams', 'hq_stock', 'hq_min_stock', 'status']),
        );

        return back()->with('status', "Produk {$product->name} berhasil diperbarui.");
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        $product->status = Product::STATUS_DELETED;
        $product->save();
        $product->delete(); // soft delete

        AuditService::log(
            action: 'delete_product',
            targetType: 'product',
            targetId: $product->id,
            before: ['status' => 'active'],
            after: ['status' => Product::STATUS_DELETED],
        );

        return back()->with('status', "Produk {$product->name} berhasil dihapus (soft delete).");
    }

    /**
     * Stok minimum pusat diisi langsung dari tabel Produk Master (tanpa buka form Edit). JSON utk isian inline;
     * validasi manual → 422 JSON (ValidationException di route web jadi redirect, dikira sukses oleh fetch).
     */
    public function updateMinStock(Request $request, Product $product): JsonResponse
    {
        $v = $request->input('hq_min_stock');
        $min = ($v === null || $v === '') ? null : filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($min === false) {
            return response()->json(['message' => 'Isi angka bulat ≥ 0 (kosong = tanpa pengingat).'], 422);
        }
        $sebelum = $product->hq_min_stock;
        $product->update(['hq_min_stock' => $min]);
        AuditService::log(action: 'update_product_min_stock', targetType: 'product', targetId: $product->id,
            before: ['hq_min_stock' => $sebelum], after: ['hq_min_stock' => $product->hq_min_stock]);

        return response()->json([
            'hq_min_stock' => $product->hq_min_stock,
            'stok' => (int) $product->hq_stock,
            'menipis' => $product->isStokPusatMenipis(),
        ]);
    }

    private function validateData(Request $request, ?Product $product = null): array
    {
        $lihatHpp = $request->user()->canDo('view_hpp');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'sku' => ['required', 'string', 'max:80', Rule::unique('products', 'sku')->ignore($product?->id)],
            'category' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price_grand' => ['nullable', 'numeric', 'min:0'],
            'price_distributor' => ['required', 'numeric', 'min:0'],
            'price_reseller' => ['required', 'numeric', 'min:0'],
            'price_retail' => ['required', 'numeric', 'min:0'],
            // HPP hanya dari yang boleh melihatnya; selainnya diabaikan (edit = HPP lama dipertahankan).
            'cogs' => $lihatHpp ? ['required', 'numeric', 'min:0'] : ['exclude'],
            'weight_grams' => ['nullable', 'integer', 'min:0'],
            'hq_stock' => ['required', 'integer', 'min:0'],
            // Stok minimum pusat (pengingat HQ menipis): kosong = tanpa pengingat.
            'hq_min_stock' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', Rule::in([Product::STATUS_ACTIVE, Product::STATUS_INACTIVE])],
            // Up to 8 photos; each auto-resized server-side, so allow large originals.
            'images' => ['nullable', 'array', 'max:'.self::MAX_IMAGES],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:12288'],
            'remove_files' => ['nullable', 'array'],
            'remove_files.*' => ['integer'],
        ]);

        // Kolom berat NOT NULL default 0: input kosong → null, jadikan 0. Kalau field
        // tak dikirim sama sekali, jangan disentuh (biar update tak menimpa jadi 0).
        if (array_key_exists('weight_grams', $data)) {
            $data['weight_grams'] = (int) $data['weight_grams'];
        }

        return $data;
    }
}
