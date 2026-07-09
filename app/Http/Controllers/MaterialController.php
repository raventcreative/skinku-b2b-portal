<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Models\MaterialPurchase;
use App\Models\Supplier;
use App\Services\AuditService;
use App\Services\MaterialService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MaterialController extends Controller
{
    public function __construct(private MaterialService $service) {}

    public function index()
    {
        $materials = Material::query()->orderBy('name')->get();
        $suppliers = Supplier::active()->ordered()->get();

        $purchases = MaterialPurchase::query()
            ->with('material', 'creator')
            ->orderByDesc('purchased_at')->orderByDesc('id')
            ->paginate(15);

        return view('materials.index', compact('materials', 'suppliers', 'purchases'));
    }

    /** Create a raw-material master record. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'unit' => ['required', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $data['created_by'] = $request->user()->id;

        $material = Material::create($data);
        AuditService::log(action: 'create_material', targetType: 'material', targetId: $material->id, after: ['name' => $material->name]);

        return back()->with('status', "Bahan baku \"{$material->name}\" ditambahkan.");
    }

    public function update(Request $request, Material $material): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'unit' => ['required', 'string', 'max:20'],
            'status' => ['required', 'in:active,inactive'],
            'avg_cost' => ['nullable', 'numeric', 'min:0'], // manual HPP override
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        // Only overwrite HPP when a value was actually entered.
        if ($data['avg_cost'] === null || $data['avg_cost'] === '') {
            unset($data['avg_cost']);
        }

        $material->update($data);
        AuditService::log(action: 'update_material', targetType: 'material', targetId: $material->id, after: ['name' => $material->name]);

        return back()->with('status', "Bahan baku \"{$material->name}\" diperbarui.");
    }

    /** Record a raw-material stock-in (purchase) — updates stock + average cost. */
    public function purchase(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'material_id' => ['required', 'integer', 'exists:materials,id'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'cost_mode' => ['nullable', Rule::in([MaterialService::MODE_AVERAGE, MaterialService::MODE_DIRECT])],
            'purchased_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $material = Material::findOrFail($data['material_id']);
        $supplier = ! empty($data['supplier_id']) ? Supplier::find($data['supplier_id']) : null;

        $this->service->addStock(
            material: $material,
            qty: (float) $data['quantity'],
            unitCost: (float) $data['unit_cost'],
            supplierId: $supplier?->id,
            supplierName: $supplier?->name,
            purchasedAt: $data['purchased_at'],
            notes: $data['notes'] ?? null,
            mode: $data['cost_mode'] ?? MaterialService::MODE_AVERAGE,
        );

        AuditService::log(action: 'purchase_material', targetType: 'material', targetId: $material->id, after: ['qty' => $data['quantity'], 'unit_cost' => $data['unit_cost'], 'mode' => $data['cost_mode'] ?? 'average']);

        return back()->with('status', "Stok bahan \"{$material->name}\" ditambah & HPP bahan diperbarui.");
    }
}
