<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Models\Product;
use App\Models\Production;
use App\Services\AuditService;
use App\Services\ProductionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'produced_at' => ['required', 'date'],
            'blocks' => ['required', 'array', 'min:1'],
            'blocks.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'blocks.*.output_qty' => ['required', 'integer', 'min:1'],
            'blocks.*.notes' => ['nullable', 'string', 'max:1000'],
            'blocks.*.materials' => ['required', 'array', 'min:1'],
            'blocks.*.materials.*.material_id' => ['nullable', 'integer', 'exists:materials,id'],
            'blocks.*.materials.*.quantity' => ['nullable', 'numeric', 'min:0.001'],
            'blocks.*.materials.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'blocks.*.costs' => ['nullable', 'array'],
            'blocks.*.costs.*.label' => ['nullable', 'string', 'max:100'],
            'blocks.*.costs.*.amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Prepare each finished-product block (materials + other costs).
        $prepared = [];
        foreach ($data['blocks'] as $block) {
            $materialLines = collect($block['materials'] ?? [])
                ->filter(fn ($r) => ! empty($r['material_id']) && ! empty($r['quantity']))
                ->map(fn ($r) => [
                    'material_id' => (int) $r['material_id'],
                    'quantity' => (float) $r['quantity'],
                    'unit_cost' => (isset($r['unit_cost']) && $r['unit_cost'] !== '') ? (float) $r['unit_cost'] : null,
                ])
                ->values()->all();

            if (empty($materialLines)) {
                return back()->withErrors(['blocks' => 'Setiap produk harus punya minimal satu baris bahan (bahan + qty pakai).'])->withInput();
            }

            $otherCosts = collect($block['costs'] ?? [])
                ->filter(fn ($r) => ! empty($r['label']) && isset($r['amount']) && $r['amount'] !== '')
                ->map(fn ($r) => ['label' => $r['label'], 'amount' => (float) $r['amount']])
                ->values()->all();

            $prepared[] = [
                'product_id' => (int) $block['product_id'],
                'output_qty' => (int) $block['output_qty'],
                'notes' => $block['notes'] ?? null,
                'materials' => $materialLines,
                'costs' => $otherCosts,
            ];
        }

        // Post all blocks atomically — one Production per finished product.
        $productions = DB::transaction(function () use ($prepared, $data) {
            $out = [];
            foreach ($prepared as $b) {
                $out[] = $this->service->produce([
                    'product_id' => $b['product_id'],
                    'produced_at' => $data['produced_at'],
                    'output_qty' => $b['output_qty'],
                    'notes' => $b['notes'],
                ], $b['materials'], $b['costs']);
            }

            return $out;
        });

        foreach ($productions as $p) {
            AuditService::log(action: 'create_production', targetType: 'production', targetId: $p->id, after: ['number' => $p->production_number, 'output_qty' => $p->output_qty, 'hpp' => $p->hpp_per_unit]);
        }

        if (count($productions) === 1) {
            return redirect()->route('productions.show', $productions[0])
                ->with('status', "Produksi {$productions[0]->production_number} tercatat. HPP/pcs = Rp ".number_format($productions[0]->hpp_per_unit, 0, ',', '.').'.');
        }

        return redirect()->route('productions.index')
            ->with('status', count($productions).' produksi tercatat sekaligus.');
    }

    public function show(Production $production)
    {
        $production->load('materials', 'costs', 'product', 'creator');

        return view('productions.show', ['production' => $production]);
    }
}
