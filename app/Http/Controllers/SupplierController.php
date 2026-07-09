<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index()
    {
        $suppliers = Supplier::ordered()->get();

        return view('suppliers.index', compact('suppliers'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()->id;

        $supplier = Supplier::create($data);
        AuditService::log(action: 'create_supplier', targetType: 'supplier', targetId: $supplier->id, after: ['name' => $supplier->name]);

        return back()->with('status', "Supplier \"{$supplier->name}\" ditambahkan.");
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($this->validated($request));
        AuditService::log(action: 'update_supplier', targetType: 'supplier', targetId: $supplier->id, after: ['name' => $supplier->name]);

        return back()->with('status', "Supplier \"{$supplier->name}\" diperbarui.");
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        $name = $supplier->name;
        $supplier->delete(); // material_purchases.supplier_id -> null (nullOnDelete)
        AuditService::log(action: 'delete_supplier', targetType: 'supplier', targetId: $supplier->id, after: ['name' => $name]);

        return back()->with('status', "Supplier \"{$name}\" dihapus.");
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);
    }
}
