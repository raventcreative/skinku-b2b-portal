<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductDevelopment;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductDevelopmentController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->only(['stage', 'q']);
        $projects = ProductDevelopment::query()
            ->with(['owner', 'product'])
            ->when($filters['stage'] ?? null, fn ($q, $stage) => $q->where('stage', $stage))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(function ($sub) use ($term) {
                $sub->where('name', 'like', "%{$term}%")
                    ->orWhere('category', 'like', "%{$term}%");
            }))
            ->orderByRaw("CASE stage
                WHEN 'research' THEN 1 WHEN 'concept' THEN 2 WHEN 'costing' THEN 3
                WHEN 'sampling' THEN 4 WHEN 'market_test' THEN 5 WHEN 'production' THEN 6
                WHEN 'launch' THEN 7 WHEN 'evaluation' THEN 8 ELSE 9 END")
            ->orderBy('target_launch_date')
            ->paginate(30)
            ->withQueryString();

        return view('product_developments.index', [
            'projects' => $projects,
            'filters' => $filters,
            'members' => User::query()->where('status', User::STATUS_ACTIVE)
                ->whereNotIn('role', [User::ROLE_DISTRIBUTOR, User::ROLE_RESELLER])
                ->orderBy('fullname')->get(),
            'products' => Product::query()->where('status', Product::STATUS_ACTIVE)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $project = ProductDevelopment::create($this->validated($request));
        AuditService::log(
            action: 'create_product_development',
            targetType: 'product_development',
            targetId: $project->id,
            after: $project->toArray(),
        );

        return back()->with('status', "Pipeline produk {$project->name} ditambahkan.");
    }

    public function update(Request $request, ProductDevelopment $productDevelopment): RedirectResponse
    {
        $before = $productDevelopment->toArray();
        $productDevelopment->update($this->validated($request));
        AuditService::log(
            action: 'update_product_development',
            targetType: 'product_development',
            targetId: $productDevelopment->id,
            before: $before,
            after: $productDevelopment->fresh()->toArray(),
        );

        return back()->with('status', "Pipeline produk {$productDevelopment->name} diperbarui.");
    }

    public function destroy(ProductDevelopment $productDevelopment): RedirectResponse
    {
        $before = $productDevelopment->toArray();
        $productDevelopment->delete();
        AuditService::log(
            action: 'delete_product_development',
            targetType: 'product_development',
            targetId: $productDevelopment->id,
            before: $before,
        );

        return back()->with('status', 'Item pipeline produk dihapus.');
    }

    /** @return array<string,mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'stage' => ['required', Rule::in(array_keys(ProductDevelopment::STAGES))],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'target_launch_date' => ['nullable', 'date_format:Y-m-d'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);
    }
}
