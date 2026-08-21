<?php

namespace App\Services;

use App\Models\PoReturn;
use App\Models\PoReturnItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Retur PO (Model A): balikin stok + clawback komisi (ro_cashback/override
 * proporsional via CommissionService + volume via re-evaluasi VolumeIncentiveService)
 * + refund manual (catat saja). Append-only. Saldo boleh minus (utang).
 */
class ReturService
{
    public function __construct(
        private InventoryService $inventory,
        private CommissionService $commissions,
        private VolumeIncentiveService $volume,
    ) {}

    /**
     * Berlakukan retur: reversal stok + clawback komisi + status applied.
     * Guard: PO completed, belum applied, qty retur ≤ sisa item.
     */
    public function apply(PoReturn $retur): PoReturn
    {
        return DB::transaction(function () use ($retur) {
            $retur->load('items.poItem', 'purchaseOrder');
            $po = $retur->purchaseOrder;

            if ($retur->status === 'applied') {
                throw new RuntimeException('Retur ini sudah diberlakukan.');
            }
            if ($po->status !== PurchaseOrder::STATUS_COMPLETED) {
                throw new RuntimeException('Hanya PO yang sudah selesai (completed) yang bisa diretur.');
            }

            $returnedValue = 0.0;
            foreach ($retur->items as $ri) {
                $poItem = $ri->poItem;
                $qty = (int) $ri->qty;
                if (! $poItem || $qty <= 0) {
                    continue;
                }

                $prior = $this->returnedQtyForPoItem($poItem->id, $retur->id);
                if ($prior + $qty > (int) $poItem->qty) {
                    throw new RuntimeException("Retur untuk {$poItem->product_name} melebihi jumlah yang dibeli.");
                }

                $returnedValue += (float) $poItem->unit_price * $qty;

                // Pembeli kirim balik barang → stok pembeli turun.
                $this->inventory->adjustPartnerStock(
                    userId: $po->user_id, productId: $poItem->product_id, delta: -$qty,
                    movementType: StockMovement::TYPE_ADJUSTMENT, notes: "Retur PO {$po->po_number}",
                    referenceType: 'po_return', referenceId: $retur->id,
                );

                // Penerima (HQ/GD) dapat stok balik HANYA kalau NORMAL (rusak = write-off).
                if ($retur->kondisi === 'normal') {
                    if ($po->seller_id === null) {
                        $product = Product::find($poItem->product_id);
                        if ($product) {
                            $this->inventory->adjustHqStock(
                                product: $product, delta: $qty, movementType: StockMovement::TYPE_ADJUSTMENT,
                                notes: "Retur masuk PO {$po->po_number}", referenceType: 'po_return', referenceId: $retur->id,
                            );
                        }
                    } else {
                        $this->inventory->adjustPartnerStock(
                            userId: $po->seller_id, productId: $poItem->product_id, delta: $qty,
                            movementType: StockMovement::TYPE_ADJUSTMENT, notes: "Retur masuk PO {$po->po_number}",
                            referenceType: 'po_return', referenceId: $retur->id,
                        );
                    }
                }
            }

            // Status applied DULU — supaya re-evaluasi volume (yang baca po_returns.status
            // = 'applied') melihat retur ini saat menghitung netTotal.
            $retur->status = 'applied';
            $retur->applied_at = now();
            $retur->save();

            // Clawback komisi proporsional (ro_cashback/override) + volume (re-eval GD).
            $subtotal = (float) $po->subtotal;
            $fraction = $subtotal > 0 ? min(1.0, $returnedValue / $subtotal) : 0.0;
            $this->commissions->recordReturnReversal($po, $fraction);
            $this->volume->evaluate($po->fresh());

            AuditService::log(action: 'apply_po_return', targetType: 'po_return', targetId: $retur->id,
                after: ['po' => $po->po_number, 'fraction' => round($fraction, 4), 'kondisi' => $retur->kondisi]);

            return $retur;
        });
    }

    /** Qty item ini yang sudah diretur (status applied), kecuali retur $exceptReturnId. */
    private function returnedQtyForPoItem(int $poItemId, int $exceptReturnId): int
    {
        return (int) PoReturnItem::query()
            ->join('po_returns', 'po_returns.id', '=', 'po_return_items.po_return_id')
            ->where('po_return_items.purchase_order_item_id', $poItemId)
            ->where('po_returns.status', 'applied')
            ->where('po_returns.id', '!=', $exceptReturnId)
            ->sum('po_return_items.qty');
    }
}
