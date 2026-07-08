<?php

namespace App\Services;

use App\Models\Material;
use App\Models\MaterialPurchase;
use App\Support\Costing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Raw-material stock-in. Adds to materials.stock and recomputes the material's
 * moving-average cost per unit.
 */
class MaterialService
{
    public function addStock(
        Material $material,
        float $qty,
        float $unitCost,
        ?string $supplier,
        string $purchasedAt,
        ?string $notes = null,
    ): MaterialPurchase {
        return DB::transaction(function () use ($material, $qty, $unitCost, $supplier, $purchasedAt, $notes) {
            $m = Material::lockForUpdate()->findOrFail($material->id);

            $beforeQty = (float) $m->stock;
            $beforeCost = (float) $m->avg_cost;
            $newCost = Costing::movingAverage($beforeQty, $beforeCost, $qty, $unitCost);

            $m->stock = $beforeQty + $qty;
            $m->avg_cost = $newCost;
            $m->save();

            return MaterialPurchase::create([
                'material_id' => $m->id,
                'material_name' => $m->name,
                'quantity' => $qty,
                'unit_cost' => round($unitCost, 2),
                'subtotal' => round($qty * $unitCost, 2),
                'cost_before' => round($beforeCost, 2),
                'cost_after' => $newCost,
                'supplier_name' => $supplier,
                'purchased_at' => $purchasedAt,
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);
        });
    }
}
