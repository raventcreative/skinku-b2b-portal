<?php

namespace App\Services;

use App\Models\PartnerSale;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Penjualan mitra ke customer akhir — "barang keluar" berbentuk nota (1 customer,
 * banyak produk). Menurunkan stok mitra pemiliknya lewat gerakan OUT.
 */
class PartnerSaleService
{
    public function __construct(private InventoryService $inventory) {}

    /**
     * Catat satu penjualan mitra dan potong stoknya, ATOMIK.
     *
     * Semua-atau-tidak: kalau satu baris melebihi stok, SELURUH penjualan
     * dibatalkan (transaksi rollback) — nota separuh jadi jauh lebih berbahaya
     * daripada gagal total, dan mencegah stok minus yang jadi sumber kekacauan.
     *
     * Total dihitung di SERVER dari qty×harga tiap baris; angka total dari klien
     * tak dipercaya.
     *
     * @param  array<int, array{product_id:int, qty:int, price:float}>  $lines
     *
     * @throws ValidationException bila tak ada baris valid
     * @throws RuntimeException bila stok tak cukup
     */
    public function record(User $seller, ?string $customerName, array $lines, ?string $notes, int $creatorId, ?Carbon $soldAt = null): PartnerSale
    {
        // Rapikan & jumlahkan qty per produk (baris ganda produk sama digabung).
        $clean = [];
        foreach ($lines as $line) {
            $pid = (int) ($line['product_id'] ?? 0);
            $qty = (int) ($line['qty'] ?? 0);
            $price = (float) ($line['price'] ?? 0);
            if ($pid <= 0 || $qty <= 0) {
                continue;
            }
            if (isset($clean[$pid])) {
                $clean[$pid]['qty'] += $qty;
            } else {
                $clean[$pid] = ['qty' => $qty, 'price' => max(0, $price)];
            }
        }

        if (empty($clean)) {
            throw ValidationException::withMessages([
                'items' => 'Pilih minimal satu produk dengan jumlah di atas 0.',
            ]);
        }

        return DB::transaction(function () use ($seller, $customerName, $clean, $notes, $creatorId, $soldAt) {
            $products = Product::whereIn('id', array_keys($clean))->lockForUpdate()->get()->keyBy('id');

            $sale = PartnerSale::create([
                'sale_number' => $this->generateNumber(),
                'user_id' => $seller->id,
                'customer_name' => $customerName ?: null,
                'total_amount' => 0,
                'notes' => $notes,
                'sold_at' => ($soldAt ?? now())->toDateString(),
                'created_by' => $creatorId,
            ]);

            $total = 0.0;
            foreach ($clean as $pid => $row) {
                $product = $products->get($pid);
                if (! $product) {
                    throw ValidationException::withMessages([
                        'items' => "Produk #{$pid} tidak ditemukan.",
                    ]);
                }

                $lineTotal = $row['qty'] * $row['price'];
                $total += $lineTotal;

                $sale->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'qty' => $row['qty'],
                    'unit_price' => $row['price'],
                    'total_price' => $lineTotal,
                ]);

                // Potong stok mitra. adjustPartnerStock melempar bila jadi negatif
                // → transaksi rollback, nota batal seluruhnya.
                $this->inventory->adjustPartnerStock(
                    userId: $seller->id,
                    productId: $product->id,
                    delta: -1 * $row['qty'],
                    movementType: StockMovement::TYPE_OUT,
                    notes: 'Penjualan '.$sale->sale_number.($customerName ? ' — '.$customerName : ''),
                    referenceType: 'partner_sale',
                    referenceId: $sale->id,
                );
            }

            $sale->update(['total_amount' => $total]);

            AuditService::log(
                action: 'partner_sale_record',
                targetType: 'partner_sale',
                targetId: $sale->id,
                after: [
                    'sale_number' => $sale->sale_number,
                    'customer' => $customerName,
                    'total' => $total,
                    'items' => count($clean),
                ],
                targetUserId: $seller->id,
            );

            return $sale->load('items');
        });
    }

    private function generateNumber(): string
    {
        $date = now()->format('Ymd');
        do {
            $candidate = sprintf('SKN-JL-%s-%04d', $date, random_int(1, 9999));
        } while (PartnerSale::where('sale_number', $candidate)->exists());

        return $candidate;
    }
}
