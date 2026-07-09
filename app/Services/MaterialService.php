<?php

namespace App\Services;

use App\Models\Material;
use App\Models\MaterialPurchase;
use App\Support\Costing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Raw-material stock-in. Adds to materials.stock and sets the material's cost:
 *   - mode 'average' (default): moving weighted average with existing stock,
 *   - mode 'direct': the material's HPP becomes exactly this unit cost (the real
 *     supplier price), ignoring the old blend.
 */
class MaterialService
{
    public const MODE_AVERAGE = 'average';

    public const MODE_DIRECT = 'direct';

    public function addStock(
        Material $material,
        float $qty,
        float $unitCost,
        ?int $supplierId,
        ?string $supplierName,
        string $purchasedAt,
        ?string $notes = null,
        string $mode = self::MODE_AVERAGE,
    ): MaterialPurchase {
        return DB::transaction(function () use ($material, $qty, $unitCost, $supplierId, $supplierName, $purchasedAt, $notes, $mode) {
            $m = Material::lockForUpdate()->findOrFail($material->id);

            $beforeQty = (float) $m->stock;
            $beforeCost = (float) $m->avg_cost;
            $newCost = $mode === self::MODE_DIRECT
                ? round($unitCost, 2)
                : Costing::movingAverage($beforeQty, $beforeCost, $qty, $unitCost);

            $m->stock = $beforeQty + $qty;
            $m->avg_cost = $newCost;
            $m->save();

            return MaterialPurchase::create([
                'material_id' => $m->id,
                'material_name' => $m->name,
                'supplier_id' => $supplierId,
                'quantity' => $qty,
                'unit_cost' => round($unitCost, 2),
                'subtotal' => round($qty * $unitCost, 2),
                'cost_before' => round($beforeCost, 2),
                'cost_after' => $newCost,
                'supplier_name' => $supplierName,
                'purchased_at' => $purchasedAt,
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);
        });
    }
}
