<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\AuditService;
use App\Services\ImageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public const MAX_IMAGES = 8;

    public function __construct(private ImageService $images) {}

    public function index(Request $request)
    {
        $filters = $request->only(['q', 'status', 'category']);

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
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $categories = Product::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category');

        return view('products.index', compact('products', 'filters', 'categories'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

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
            after: $product->only(['name', 'sku', 'price_grand', 'price_distributor', 'price_reseller', 'price_retail', 'cogs', 'hq_stock', 'status']),
        );

        return back()->with('status', "Produk {$product->name} berhasil ditambahkan.");
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $this->validateData($request, $product);

        $before = $product->only(['name', 'sku', 'price_grand', 'price_distributor', 'price_reseller', 'price_retail', 'cogs', 'hq_stock', 'status']);

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
            after: $product->only(['name', 'sku', 'price_grand', 'price_distributor', 'price_reseller', 'price_retail', 'cogs', 'hq_stock', 'status']),
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

    private function validateData(Request $request, ?Product $product = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'sku' => ['required', 'string', 'max:80', Rule::unique('products', 'sku')->ignore($product?->id)],
            'category' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price_grand' => ['nullable', 'numeric', 'min:0'],
            'price_distributor' => ['required', 'numeric', 'min:0'],
            'price_reseller' => ['required', 'numeric', 'min:0'],
            'price_retail' => ['required', 'numeric', 'min:0'],
            'cogs' => ['required', 'numeric', 'min:0'],
            'hq_stock' => ['required', 'integer', 'min:0'],
            'status' => ['required', Rule::in([Product::STATUS_ACTIVE, Product::STATUS_INACTIVE])],
            // Up to 8 photos; each auto-resized server-side, so allow large originals.
            'images' => ['nullable', 'array', 'max:'.self::MAX_IMAGES],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:12288'],
            'remove_files' => ['nullable', 'array'],
            'remove_files.*' => ['integer'],
        ]);
    }
}
