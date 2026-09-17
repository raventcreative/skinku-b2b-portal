<?php

namespace App\Services;

use App\Models\Material;
use App\Models\Product;
use App\Models\Production;
use App\Models\StockMovement;
use App\Models\StockReceiptItem;
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
 * Edit & delete reverse these effects (return materials, pull product stock)
 * and then recompute the product's HPP from scratch — a chronological moving
 * average over ALL its cost-in events (productions + stock receipts), seeded
 * from the opening basis. So any batch can be edited/deleted regardless of
 * order; the only guard is that this batch's output must not be sold yet.
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

            $this->applyEffects($production, $header, $materialLines, $otherCosts);
            $this->recompute(Product::lockForUpdate()->findOrFail($production->product_id));

            return $production->refresh();
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
            $this->applyEffects($production, $header + ['product_id' => $production->product_id], $materialLines, $otherCosts);
            $this->recompute(Product::lockForUpdate()->findOrFail($production->product_id));

            return $production->refresh();
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
            $this->recompute(Product::lockForUpdate()->findOrFail($product->id));
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

    /**
     * Boleh dibalik selama stok produk jadi masih cukup untuk menarik kembali
     * hasil produksi ini (kalau kurang → sebagian sudah terjual). Urutan HPP TAK
     * lagi jadi syarat: HPP dihitung ulang dari seluruh produksi (recompute()).
     */
    private function assertReversible(Production $production, Product $product): void
    {
        if ((int) $product->hq_stock < (int) $production->output_qty) {
            throw new RuntimeException('Tidak bisa diubah/dihapus: stok produk jadi ('.(int) $product->hq_stock.') kurang dari hasil produksi ini ('.(int) $production->output_qty.') — sebagian sudah terjual/terpakai.');
        }
    }

    /**
     * Hitung ULANG HPP (cogs) produk dari SELURUH kejadian stok-masuk-nya —
     * produksi DAN stok masuk (GRN) — urut tanggal, rata-rata bergerak. Inilah
     * yang membuat edit/hapus produksi mana pun konsisten TANPA peduli urutan:
     * kejadian lain (produksi & GRN sesudahnya) ikut ter-recompute.
     *
     * Saldo awal (qty & cogs sebelum kejadian pertama) dipertahankan supaya HPP
     * awal yang di-set manual di master produk / stok opname tidak hilang saat
     * di-hitung ulang. Sekaligus memperbarui cogs_before/after tiap baris.
     */
    private function recompute(Product $product): void
    {
        // Kumpulkan semua kejadian penambah stok berbasis biaya, urut tanggal.
        $events = collect();

        Production::where('product_id', $product->id)->get()
            ->each(fn (Production $p) => $events->push([
                'date' => $p->produced_at,
                'created' => $p->created_at,
                'type' => 0, // produksi diproses lebih dulu bila tanggal & waktu buat sama
                'id' => (int) $p->id,
                'qty' => (int) $p->output_qty,
                'rate' => (float) $p->hpp_per_unit,
                'row' => $p,
            ]));

        StockReceiptItem::where('product_id', $product->id)->with('receipt')->get()
            ->each(function (StockReceiptItem $it) use ($events) {
                if (! $it->receipt) {
                    return;
                }
                $events->push([
                    'date' => $it->receipt->received_at,
                    'created' => $it->created_at,
                    'type' => 1,
                    'id' => (int) $it->id,
                    'qty' => (int) $it->quantity,
                    'rate' => (float) $it->unit_cost,
                    'row' => $it,
                ]);
            });

        // Urut kronologis via satu kunci gabungan (bentuk array sortBy pakai
        // closure pembanding 2-argumen — mudah salah; kunci tunggal lebih aman).
        $events = $events->sortBy(function ($e) {
            $date = str_pad((string) (optional($e['date'])->timestamp ?? 0), 12, '0', STR_PAD_LEFT);
            $created = str_pad((string) (optional($e['created'])->timestamp ?? 0), 12, '0', STR_PAD_LEFT);
            $id = str_pad((string) $e['id'], 12, '0', STR_PAD_LEFT);

            return $date.$created.$e['type'].$id;
        })->values();

        if ($events->isEmpty()) {
            $product->cogs = 0.0;
            $product->save();

            return;
        }

        // Saldo awal sebelum kejadian pertama: cost = cogs_before tersimpan;
        // qty diturunkan dari snapshot kejadian pertama agar basis manual/GRN awal
        // ikut tertimbang. Tanpa basis awal (cogs_before 0) → mulai dari nol.
        $first = $events->first();
        $openCogs = round((float) $first['row']->cogs_before, 2);
        $openQty = $this->deriveOpeningQty(
            $openCogs,
            round((float) $first['row']->cogs_after, 2),
            $first['rate'],
            $first['qty'],
        );

        $qty = (float) $openQty;
        $avg = $openCogs;
        foreach ($events as $e) {
            $before = $avg;
            $avg = Costing::movingAverage($qty, $avg, (float) $e['qty'], $e['rate']);
            $qty += (float) $e['qty'];
            $e['row']->cogs_before = round($before, 2);
            $e['row']->cogs_after = round($avg, 2);
            $e['row']->saveQuietly();
        }

        $product->cogs = round($avg, 2);
        $product->save();
    }

    /**
     * Qty saldo awal (sebelum kejadian pertama) yang disimpulkan dari snapshot
     * kejadian pertama, supaya basis HPP awal (di-set manual / stok opname)
     * tetap tertimbang benar saat recompute. 0 bila tak ada basis awal.
     *
     *   cogs_after1 = (openQty*openCogs + in1*rate1) / (openQty + in1)
     *   → openQty   = in1 * (rate1 - cogs_after1) / (cogs_after1 - openCogs)
     */
    private function deriveOpeningQty(float $openCogs, float $firstAfter, float $firstRate, int $firstQty): int
    {
        if ($openCogs <= 0) {
            return 0; // Tak ada basis biaya awal → mulai dari nol.
        }
        $denom = $firstAfter - $openCogs;
        if (abs($denom) < 0.005) {
            return 0; // Tak tentu (rate = cogs awal); nilai rata2 tak berubah oleh qty awal.
        }

        return max(0, (int) round($firstQty * ($firstRate - $firstAfter) / $denom));
    }
}
