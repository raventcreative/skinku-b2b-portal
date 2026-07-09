<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Models\Product;
use App\Models\Production;
use App\Services\AuditService;
use App\Services\ProductionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ProductionController extends Controller
{
    public function __construct(private ProductionService $service) {}

    public function index()
    {
        $productions = Production::query()
            ->with('product', 'creator')
            ->orderByDesc('produced_at')->orderByDesc('id')
            ->paginate(20);

        return view('productions.index', compact('productions'));
    }

    public function create()
    {
        $products = Product::query()
            ->where('status', '!=', Product::STATUS_DELETED)
            ->orderBy('name')->get(['id', 'name', 'sku', 'hq_stock', 'cogs']);

        $materials = Material::active()
            ->orderBy('name')->get(['id', 'name', 'unit', 'stock', 'avg_cost']);

        return view('productions.create', compact('products', 'materials'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'produced_at' => ['required', 'date'],
            'output_qty' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'materials' => ['required', 'array', 'min:1'],
            'materials.*.material_id' => ['nullable', 'integer', 'exists:materials,id'],
            'materials.*.quantity' => ['nullable', 'numeric', 'min:0.001'],
            'materials.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'costs' => ['nullable', 'array'],
            'costs.*.label' => ['nullable', 'string', 'max:100'],
            'costs.*.amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $materialLines = collect($data['materials'])
            ->filter(fn ($r) => ! empty($r['material_id']) && ! empty($r['quantity']))
            ->map(fn ($r) => [
                'material_id' => (int) $r['material_id'],
                'quantity' => (float) $r['quantity'],
                'unit_cost' => (isset($r['unit_cost']) && $r['unit_cost'] !== '') ? (float) $r['unit_cost'] : null,
            ])
            ->values()->all();

        if (empty($materialLines)) {
            return back()->withErrors(['materials' => 'Tambahkan minimal satu baris pemakaian bahan (bahan + qty pakai).'])->withInput();
        }

        $otherCosts = collect($data['costs'] ?? [])
            ->filter(fn ($r) => ! empty($r['label']) && isset($r['amount']) && $r['amount'] !== '')
            ->map(fn ($r) => ['label' => $r['label'], 'amount' => (float) $r['amount']])
            ->values()->all();

        try {
            $production = $this->service->produce([
                'product_id' => (int) $data['product_id'],
                'produced_at' => $data['produced_at'],
                'output_qty' => (int) $data['output_qty'],
                'notes' => $data['notes'] ?? null,
            ], $materialLines, $otherCosts);
        } catch (RuntimeException $e) {
            return back()->withErrors(['materials' => $e->getMessage()])->withInput();
        }

        AuditService::log(
            action: 'create_production',
            targetType: 'production',
            targetId: $production->id,
            after: ['number' => $production->production_number, 'output_qty' => $production->output_qty, 'hpp' => $production->hpp_per_unit],
        );

        return redirect()->route('productions.show', $production)
            ->with('status', "Produksi {$production->production_number} tercatat. HPP/pcs = Rp ".number_format($production->hpp_per_unit, 0, ',', '.').'.');
    }

    public function show(Production $production)
    {
        $production->load('materials', 'costs', 'product', 'creator');

        return view('productions.show', ['production' => $production]);
    }
}
