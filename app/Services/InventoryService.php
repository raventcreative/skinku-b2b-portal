<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Stock mutation helpers. All public methods are atomic and always write a
 * matching stock_movements ledger row so HQ + partner stock stay auditable.
 */
class InventoryService
{
    /**
     * Adjust HQ (pusat) stock on a product. Positive qty = stock in,
     * negative qty = stock out. Returns the resulting product.
     *
     * @throws RuntimeException when an OUT movement would go negative.
     */
    public function adjustHqStock(
        Product $product,
        int $delta,
        string $movementType,
        ?string $notes = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?\DateTimeInterface $occurredAt = null,
    ): Product {
        return DB::transaction(function () use ($product, $delta, $movementType, $notes, $referenceType, $referenceId, $occurredAt) {
            $product = Product::lockForUpdate()->findOrFail($product->id);
            $before = (int) $product->hq_stock;
            $after = $before + $delta;

            if ($after < 0) {
                throw new RuntimeException("Stok pusat tidak mencukupi untuk produk {$product->name}. Tersedia {$before}, diminta ".abs($delta).'.');
            }

            $product->hq_stock = $after;
            $product->save();

            $this->writeMovement(
                productId: $product->id,
                userId: null, // HQ
                type: $movementType,
                quantity: abs($delta),
                before: $before,
                after: $after,
                notes: $notes,
                referenceType: $referenceType,
                referenceId: $referenceId,
                occurredAt: $occurredAt,
            );

            return $product;
        });
    }

    /**
     * Adjust a partner's inventory line for a product. Creates the line when
     * it does not yet exist. Positive = in, negative = out.
     *
     * @throws RuntimeException when an OUT movement would go negative.
     */
    public function adjustPartnerStock(
        int $userId,
        int $productId,
        int $delta,
        string $movementType,
        ?string $notes = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): Inventory {
        return DB::transaction(function () use ($userId, $productId, $delta, $movementType, $notes, $referenceType, $referenceId) {
            $line = Inventory::lockForUpdate()->firstOrNew([
                'user_id' => $userId,
                'product_id' => $productId,
            ]);

            $before = (int) ($line->quantity ?? 0);
            $after = $before + $delta;

            if ($after < 0) {
                throw new RuntimeException('Stok mitra tidak mencukupi untuk dikurangi.');
            }

            $line->quantity = $after;
            $line->minimum_stock = $line->minimum_stock ?? 0;
            $line->last_updated = now();
            $line->save();

            $this->writeMovement(
                productId: $productId,
                userId: $userId,
                type: $movementType,
                quantity: abs($delta),
                before: $before,
                after: $after,
                notes: $notes,
                referenceType: $referenceType,
                referenceId: $referenceId,
            );

            return $line;
        });
    }

    /**
     * SET stok mitra ke angka absolut (saldo awal / koreksi hasil hitung fisik).
     *
     * Selisih dihitung DI DALAM transaksi setelah baris dikunci, bukan dari
     * angka yang dikirim klien — kalau tidak, dua penyesuaian bersamaan bisa
     * saling menimpa. Tercatat sebagai ADJUSTMENT (before→after menyimpan arah).
     *
     * Ini pengganti dropdown Masuk/Keluar/Penyesuaian yang membingungkan: mitra
     * cukup menyatakan "stok sebenarnya sekian", sistem yang menghitung deltanya.
     *
     * @throws RuntimeException bila target negatif atau tak ada perubahan
     */
    public function setPartnerStock(int $userId, int $productId, int $target, ?string $notes = null): Inventory
    {
        if ($target < 0) {
            throw new RuntimeException('Stok tidak boleh negatif.');
        }

        return DB::transaction(function () use ($userId, $productId, $target, $notes) {
            $line = Inventory::lockForUpdate()->firstOrNew([
                'user_id' => $userId,
                'product_id' => $productId,
            ]);

            $before = (int) ($line->quantity ?? 0);
            $delta = $target - $before;

            if ($delta === 0) {
                throw new RuntimeException("Stok sudah {$target}, tidak ada yang diubah.");
            }

            $line->quantity = $target;
            $line->minimum_stock = $line->minimum_stock ?? 0;
            $line->last_updated = now();
            $line->save();

            $this->writeMovement(
                productId: $productId,
                userId: $userId,
                type: StockMovement::TYPE_ADJUSTMENT,
                quantity: abs($delta),
                before: $before,
                after: $target,
                notes: $notes,
            );

            return $line;
        });
    }

    /** Update only the replenishment threshold for a partner stock line. */
    public function setPartnerMinimum(int $userId, int $productId, int $minimum): Inventory
    {
        $line = Inventory::firstOrNew(['user_id' => $userId, 'product_id' => $productId]);
        $line->quantity = $line->quantity ?? 0;
        $line->minimum_stock = max(0, $minimum);
        $line->last_updated = now();
        $line->save();

        return $line;
    }

    public function writeMovement(
        int $productId,
        ?int $userId,
        string $type,
        int $quantity,
        int $before,
        int $after,
        ?string $notes = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?\DateTimeInterface $occurredAt = null,
    ): StockMovement {
        return StockMovement::create([
            'product_id' => $productId,
            'user_id' => $userId,
            'movement_type' => $type,
            'quantity' => $quantity,
            'before_qty' => $before,
            'after_qty' => $after,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'notes' => $notes,
            'created_by' => Auth::id(),
            'created_at' => $occurredAt ?? now(),
        ]);
    }
}
