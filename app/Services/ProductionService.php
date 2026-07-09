<?php

namespace App\Services;

use App\Models\Material;
use App\Models\Product;
use App\Models\Production;
use App\Models\StockMovement;
use App\Support\Costing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Production / repacking posting. For one batch:
 *   1. consume each material (reduce materials.stock — allowed to go negative;
 *      stock is only for valuation, it does not block a repack). The per-unit
 *      cost is the price typed on the line, or the material's average cost when
 *      left blank,
 *   2. add any non-material costs (ongkir, tenaga kerja, ...),
 *   3. hpp_per_unit = (material_cost + other_cost) / output_qty,
 *   4. raise the finished product's hq_stock (+output_qty, IN movement) and
 *      update its moving-average HPP (products.cogs).
 */
class ProductionService
{
    public function __construct(private InventoryService $inventory) {}

    /**
     * @param  array{product_id:int, output_qty:int, produced_at:string, notes?:?string}  $header
     * @param  array<int, array{material_id:int, quantity:float, unit_cost?:float|null}>  $materialLines
     * @param  array<int, array{label:string, amount:float}>  $otherCosts
     */
    public function produce(array $header, array $materialLines, array $otherCosts): Production
    {
        return DB::transaction(function () use ($header, $materialLines, $otherCosts) {
            $product = Product::lockForUpdate()->findOrFail($header['product_id']);
            $outputQty = (int) $header['output_qty'];

            $production = Production::create([
                'production_number' => 'PRD-TEMP',
                'product_id' => $product->id,
                'product_name' => $product->name,
                'produced_at' => $header['produced_at'],
                'output_qty' => $outputQty,
                'notes' => $header['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);
            $production->production_number = 'PRD-'.str_pad((string) $production->id, 5, '0', STR_PAD_LEFT);

            // 1. Consume materials (repacking: stock may go negative, never blocks).
            $materialCost = 0.0;
            foreach ($materialLines as $line) {
                $material = Material::lockForUpdate()->findOrFail($line['material_id']);
                $qty = (float) $line['quantity'];

                // Typed per-unit price for this batch, or fall back to the average cost.
                $unitCost = isset($line['unit_cost']) && $line['unit_cost'] !== null && $line['unit_cost'] !== ''
                    ? round((float) $line['unit_cost'], 2)
                    : (float) $material->avg_cost;
                $subtotal = round($qty * $unitCost, 2);

                $material->stock = (float) $material->stock - $qty;
                $material->save();

                $production->materials()->create([
                    'material_id' => $material->id,
                    'material_name' => $material->name,
                    'unit' => $material->unit,
                    'quantity' => $qty,
                    'unit_cost' => round($unitCost, 2),
                    'subtotal' => $subtotal,
                ]);

                $materialCost += $subtotal;
            }

            // 2. Non-material costs.
            $otherCost = 0.0;
            foreach ($otherCosts as $cost) {
                $amount = round((float) $cost['amount'], 2);
                $production->costs()->create(['label' => $cost['label'], 'amount' => $amount]);
                $otherCost += $amount;
            }

            // 3. HPP per unit.
            $total = round($materialCost + $otherCost, 2);
            $hpp = $outputQty > 0 ? round($total / $outputQty, 2) : 0.0;

            // 4. Finished product: stock + moving-average HPP.
            $beforeQty = (int) $product->hq_stock;
            $beforeCogs = (float) $product->cogs;

            $this->inventory->adjustHqStock(
                product: $product,
                delta: $outputQty,
                movementType: StockMovement::TYPE_IN,
                notes: 'Hasil produksi '.$production->production_number,
                referenceType: Production::REFERENCE_TYPE,
                referenceId: $production->id,
            );

            $newCogs = Costing::movingAverage($beforeQty, $beforeCogs, $outputQty, $hpp);
            $product->cogs = $newCogs;
            $product->save();

            $production->material_cost = $materialCost;
            $production->other_cost = $otherCost;
            $production->total_cost = $total;
            $production->hpp_per_unit = $hpp;
            $production->cogs_before = round($beforeCogs, 2);
            $production->cogs_after = $newCogs;
            $production->save();

            return $production;
        });
    }
}
