<?php

namespace App\Services;

use App\Models\Material;
use App\Models\Product;
use App\Models\Production;
use App\Models\StockMovement;
use App\Support\Costing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
 *
 * Edit & delete reverse these effects (return materials, pull product stock,
 * restore HPP) — only while this batch is still the last to touch the product's
 * HPP and its output is not yet consumed, so the moving average stays consistent.
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
            $product = Product::findOrFail((int) $header['product_id']);
            $production = Production::create([
                'production_number' => 'PRD-TEMP',
                'product_id' => $product->id,
                'product_name' => $product->name,
                'produced_at' => $header['produced_at'],
                'output_qty' => (int) $header['output_qty'],
                'notes' => $header['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);
            $production->production_number = 'PRD-'.str_pad((string) $production->id, 5, '0', STR_PAD_LEFT);

            return $this->applyEffects($production, $header, $materialLines, $otherCosts);
        });
    }

    /**
     * Ubah produksi: balik dampak lama lalu terapkan data baru pada record yang
     * SAMA (tak perlu input ulang & tak bikin nomor baru). Produk tetap.
     *
     * @param  array{output_qty:int, produced_at:string, notes?:?string}  $header
     * @param  array<int, array{material_id:int, quantity:float, unit_cost?:float|null}>  $materialLines
     * @param  array<int, array{label:string, amount:float}>  $otherCosts
     */
    public function update(Production $production, array $header, array $materialLines, array $otherCosts): Production
    {
        return DB::transaction(function () use ($production, $header, $materialLines, $otherCosts) {
            $product = Product::lockForUpdate()->findOrFail($production->product_id);
            $this->assertReversible($production, $product);
            $this->undoEffects($production);

            return $this->applyEffects($production, $header + ['product_id' => $production->product_id], $materialLines, $otherCosts);
        });
    }

    /**
     * Batalkan (hapus) satu produksi & PULIHKAN dampaknya (bahan kembali, stok
     * produk ditarik, HPP dipulihkan). Guard sama dengan update.
     */
    public function reverse(Production $production): void
    {
        DB::transaction(function () use ($production) {
            $product = Product::lockForUpdate()->findOrFail($production->product_id);
            $this->assertReversible($production, $product);
            $this->undoEffects($production);
            $production->delete();
        });
    }

    /** Konsumsi bahan + biaya → HPP → stok produk + rata-rata bergerak. Pada record yg ada. */
    private function applyEffects(Production $production, array $header, array $materialLines, array $otherCosts): Production
    {
        $product = Product::lockForUpdate()->findOrFail((int) $header['product_id']);
        $outputQty = (int) $header['output_qty'];

        $production->product_id = $product->id;
        $production->product_name = $product->name;
        $production->produced_at = $header['produced_at'];
        $production->output_qty = $outputQty;
        $production->notes = $header['notes'] ?? null;

        // 1. Consume materials (repacking: stock may go negative, never blocks).
        $materialCost = 0.0;
        foreach ($materialLines as $line) {
            $material = Material::lockForUpdate()->findOrFail((int) $line['material_id']);
            $qty = (float) $line['quantity'];
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
            occurredAt: Carbon::parse($header['produced_at']),
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
    }

    /** Balik dampak produksi (bahan kembali, stok produk turun, HPP dipulihkan) + hapus rincian lama. */
    private function undoEffects(Production $production): void
    {
        $production->loadMissing('materials');
        $product = Product::lockForUpdate()->findOrFail($production->product_id);

        foreach ($production->materials as $line) {
            $material = Material::lockForUpdate()->find($line->material_id);
            if ($material) {
                $material->stock = (float) $material->stock + (float) $line->quantity;
                $material->save();
            }
        }

        $this->inventory->adjustHqStock(
            product: $product,
            delta: -1 * (int) $production->output_qty,
            movementType: StockMovement::TYPE_OUT,
            notes: 'Pembatalan produksi '.$production->production_number,
            referenceType: Production::REFERENCE_TYPE,
            referenceId: $production->id,
        );

        if ($production->cogs_before !== null) {
            $product = Product::lockForUpdate()->findOrFail($product->id);
            $product->cogs = round((float) $production->cogs_before, 2);
            $product->save();
        }

        $production->costs()->delete();
        $production->materials()->delete();
        $production->setRelation('materials', collect());
        $production->setRelation('costs', collect());
    }

    /** Boleh dibalik hanya bila produksi ini yg terakhir ubah HPP & hasilnya belum terjual. */
    private function assertReversible(Production $production, Product $product): void
    {
        if ($production->cogs_after !== null && abs((float) $product->cogs - (float) $production->cogs_after) > 0.01) {
            throw new RuntimeException('Tidak bisa diubah/dihapus: HPP produk sudah diubah produksi lain SETELAH ini. Ubah/hapus produksi yang lebih baru dulu.');
        }
        if ((int) $product->hq_stock < (int) $production->output_qty) {
            throw new RuntimeException('Tidak bisa diubah/dihapus: sebagian/seluruh hasil produksi sudah terjual/terpakai (stok pusat '.(int) $product->hq_stock.', butuh '.(int) $production->output_qty.').');
        }
    }
}
