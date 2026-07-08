<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockReceipt;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Records incoming stock (goods receipt). Posting a receipt is atomic and, per
 * line, does three things:
 *   1. raises products.hq_stock (+qty) and writes an IN stock_movements row,
 *   2. recomputes the product's moving-average HPP (products.cogs),
 *   3. stores the line with a before/after cost snapshot.
 *
 * Moving weighted average:
 *   new_cogs = (old_qty * old_cogs + qty_in * unit_cost) / (old_qty + qty_in)
 * When there is no prior cost basis (no stock or cogs = 0) the incoming
 * unit_cost simply becomes the new average.
 */
class StockReceiptService
{
    public function __construct(private InventoryService $inventory) {}

    /**
     * @param  array{supplier_name?:?string, reference_no?:?string, received_at:string, notes?:?string}  $header
     * @param  array<int, array{product_id:int, quantity:int, unit_cost:float}>  $lines
     */
    public function receive(array $header, array $lines): StockReceipt
    {
        return DB::transaction(function () use ($header, $lines) {
            $receipt = StockReceipt::create([
                'receipt_number' => 'GRN-TEMP',
                'supplier_name' => $header['supplier_name'] ?? null,
                'reference_no' => $header['reference_no'] ?? null,
                'received_at' => $header['received_at'],
                'notes' => $header['notes'] ?? null,
                'total_cost' => 0,
                'created_by' => Auth::id(),
            ]);

            $receipt->receipt_number = 'GRN-'.str_pad((string) $receipt->id, 5, '0', STR_PAD_LEFT);

            $total = 0.0;

            foreach ($lines as $line) {
                $product = Product::lockForUpdate()->findOrFail($line['product_id']);

                $qty = (int) $line['quantity'];
                $unitCost = round((float) $line['unit_cost'], 2);
                $subtotal = round($qty * $unitCost, 2);

                $beforeQty = (int) $product->hq_stock;
                $beforeCogs = (float) $product->cogs;
                $newCogs = $this->weightedAverage($beforeQty, $beforeCogs, $qty, $unitCost);

                // Stock in + ledger row (references this receipt).
                $this->inventory->adjustHqStock(
                    product: $product,
                    delta: $qty,
                    movementType: StockMovement::TYPE_IN,
                    notes: 'Stok masuk '.$receipt->receipt_number.' @ Rp '.number_format($unitCost, 0, ',', '.'),
                    referenceType: StockReceipt::REFERENCE_TYPE,
                    referenceId: $receipt->id,
                );

                // Update the average HPP on the product.
                $product->cogs = $newCogs;
                $product->save();

                $receipt->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'subtotal' => $subtotal,
                    'cogs_before' => round($beforeCogs, 2),
                    'cogs_after' => $newCogs,
                ]);

                $total += $subtotal;
            }

            $receipt->total_cost = round($total, 2);
            $receipt->save();

            return $receipt;
        });
    }

    /** Moving weighted-average unit cost, rounded to 2 dp. */
    public function weightedAverage(int $beforeQty, float $beforeCogs, int $qtyIn, float $unitCost): float
    {
        if ($beforeQty <= 0 || $beforeCogs <= 0) {
            return round($unitCost, 2);
        }

        $newQty = $beforeQty + $qtyIn;
        if ($newQty <= 0) {
            return round($unitCost, 2);
        }

        return round((($beforeQty * $beforeCogs) + ($qtyIn * $unitCost)) / $newQty, 2);
    }
}
