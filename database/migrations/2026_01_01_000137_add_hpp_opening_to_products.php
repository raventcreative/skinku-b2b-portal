<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Basis stok & HPP produk SEBELUM kejadian penambah-stok pertamanya (produksi /
 * stok masuk). Disimpan eksplisit supaya HPP bisa dihitung ULANG dari nol
 * (recompute) secara TEPAT saat produksi mana pun diedit/dihapus — tanpa perlu
 * menebak saldo awal dari snapshot yang bisa berubah.
 *
 * Backfill: untuk produk yang sudah punya kejadian, saldo awal disimpulkan dari
 * snapshot kejadian TERAWAL (cogs_before + qty yang diturunkan darinya). Produk
 * manufaktur yang mulai dari nol → (0, 0).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->integer('hpp_opening_qty')->default(0)->after('hq_stock');
            $table->decimal('hpp_opening_cogs', 15, 2)->default(0)->after('hpp_opening_qty');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['hpp_opening_qty', 'hpp_opening_cogs']);
        });
    }

    /** Isi saldo awal dari kejadian terawal tiap produk. */
    private function backfill(): void
    {
        $productIds = DB::table('productions')->distinct()->pluck('product_id')
            ->merge(DB::table('stock_receipt_items')->distinct()->pluck('product_id'))
            ->unique()->filter();

        foreach ($productIds as $pid) {
            $first = $this->earliestEvent((int) $pid);
            if ($first === null) {
                continue;
            }

            $openCogs = round((float) $first['cogs_before'], 2);
            $openQty = $this->deriveOpeningQty($openCogs, round((float) $first['cogs_after'], 2), (float) $first['rate'], (int) $first['qty']);

            DB::table('products')->where('id', $pid)->update([
                'hpp_opening_qty' => $openQty,
                'hpp_opening_cogs' => $openCogs,
            ]);
        }
    }

    /** @return array{cogs_before:float,cogs_after:float,rate:float,qty:int}|null */
    private function earliestEvent(int $productId): ?array
    {
        $prod = DB::table('productions')->where('product_id', $productId)
            ->orderBy('produced_at')->orderBy('id')->first();
        $rcv = DB::table('stock_receipt_items')
            ->join('stock_receipts', 'stock_receipts.id', '=', 'stock_receipt_items.stock_receipt_id')
            ->where('stock_receipt_items.product_id', $productId)
            ->orderBy('stock_receipts.received_at')->orderBy('stock_receipt_items.id')
            ->select('stock_receipt_items.*', 'stock_receipts.received_at')
            ->first();

        $prodDate = $prod?->produced_at;
        $rcvDate = $rcv?->received_at;

        // Pilih yang terawal; seri → produksi lebih dulu.
        $useProd = $prod !== null && ($rcv === null || $prodDate <= $rcvDate);

        if ($useProd) {
            return ['cogs_before' => $prod->cogs_before, 'cogs_after' => $prod->cogs_after, 'rate' => $prod->hpp_per_unit, 'qty' => $prod->output_qty];
        }
        if ($rcv !== null) {
            return ['cogs_before' => $rcv->cogs_before, 'cogs_after' => $rcv->cogs_after, 'rate' => $rcv->unit_cost, 'qty' => $rcv->quantity];
        }

        return null;
    }

    private function deriveOpeningQty(float $openCogs, float $firstAfter, float $firstRate, int $firstQty): int
    {
        if ($openCogs <= 0) {
            return 0;
        }
        $denom = $firstAfter - $openCogs;
        if (abs($denom) < 0.005) {
            return 0;
        }

        return max(0, (int) round($firstQty * ($firstRate - $firstAfter) / $denom));
    }
};
