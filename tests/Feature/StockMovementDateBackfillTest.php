<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Production;
use App\Models\StockMovement;
use App\Support\StockMovementDateBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockMovementDateBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_geser_created_at_gerakan_ke_tanggal_produksi(): void
    {
        $product = Product::create([
            'name' => 'Mizu', 'sku' => 'MZ-500ML',
            'price_distributor' => 29000, 'price_reseller' => 38000, 'price_retail' => 65000,
            'cogs' => 14000, 'hq_stock' => 998, 'status' => 'active',
        ]);
        $prod = Production::create([
            'production_number' => 'PRD-00001', 'product_id' => $product->id, 'product_name' => $product->name,
            'produced_at' => '2026-08-07', 'output_qty' => 998, 'created_by' => null,
        ]);
        // Gerakan lama SALAH tanggal (dicap tanggal input 11 Agu, bukan produced_at 7 Agu).
        StockMovement::create([
            'product_id' => $product->id, 'user_id' => null, 'movement_type' => 'in', 'quantity' => 998,
            'before_qty' => 0, 'after_qty' => 998, 'reference_type' => 'production', 'reference_id' => $prod->id,
            'created_at' => '2026-08-11 10:00:00',
        ]);

        StockMovementDateBackfill::run();

        $mv = StockMovement::where('reference_type', 'production')->where('reference_id', $prod->id)->first();
        $this->assertSame('2026-08-07', $mv->created_at->toDateString());
    }
}
