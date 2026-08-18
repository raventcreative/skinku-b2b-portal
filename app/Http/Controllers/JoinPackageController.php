<?php

namespace App\Http\Controllers;

use App\Models\JoinPackage;
use App\Models\Product;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class JoinPackageController extends Controller
{
    public function index()
    {
        $packages = JoinPackage::withCount('items')->orderBy('name')->get();

        return view('join_packages.index', ['packages' => $packages]);
    }

    public function create()
    {
        return view('join_packages.form', ['package' => new JoinPackage, 'products' => $this->products()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($data) {
            $paket = JoinPackage::create([
                'name' => $data['name'], 'target_role' => $data['target_role'],
                'price' => $data['price'], 'is_active' => (bool) ($data['is_active'] ?? false),
            ]);
            foreach ($data['items'] as $it) {
                $paket->items()->create(['product_id' => $it['product_id'], 'qty' => $it['qty']]);
            }
        });
        AuditService::log(action: 'create_join_package', targetType: 'join_package', after: ['name' => $data['name']]);

        return redirect()->route('join-packages.index')->with('status', 'Paket join dibuat.');
    }

    public function edit(JoinPackage $joinPackage)
    {
        $joinPackage->load('items');

        return view('join_packages.form', ['package' => $joinPackage, 'products' => $this->products()]);
    }

    public function update(Request $request, JoinPackage $joinPackage): RedirectResponse
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($data, $joinPackage) {
            $joinPackage->update([
                'name' => $data['name'], 'target_role' => $data['target_role'],
                'price' => $data['price'], 'is_active' => (bool) ($data['is_active'] ?? false),
            ]);
            $joinPackage->items()->delete();
            foreach ($data['items'] as $it) {
                $joinPackage->items()->create(['product_id' => $it['product_id'], 'qty' => $it['qty']]);
            }
        });

        return redirect()->route('join-packages.index')->with('status', 'Paket join diperbarui.');
    }

    public function destroy(JoinPackage $joinPackage): RedirectResponse
    {
        $joinPackage->delete();

        return redirect()->route('join-packages.index')->with('status', 'Paket join dihapus.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'target_role' => ['required', Rule::in([User::ROLE_RESELLER_BRONZE, User::ROLE_RESELLER_GOLD])],
            'price' => ['required', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
        ]);
    }

    private function products()
    {
        return Product::where('status', Product::STATUS_ACTIVE)->orderBy('name')->get();
    }
}
