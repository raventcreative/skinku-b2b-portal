<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockMovement;
use App\Services\ProductionService;
use App\Services\StockReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StockBackdateMovementTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function product(): Product
    {
        return Product::create([
            'name' => 'Produk '.(++$this->seq), 'sku' => 'SKU-'.$this->seq,
            'price_distributor' => 24000, 'price_reseller' => 29000, 'price_retail' => 39000,
            'cogs' => 10000, 'hq_stock' => 0, 'status' => 'active',
        ]);
    }

    public function test_produksi_backdate_gerakan_stok_pakai_tanggal_produksi(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');
        $p = $this->product();

        app(ProductionService::class)->produce(
            ['product_id' => $p->id, 'output_qty' => 998, 'produced_at' => '2026-08-07', 'notes' => null],
            [], []
        );

        $mv = StockMovement::where('reference_type', 'production')->where('product_id', $p->id)->first();
        $this->assertNotNull($mv);
        $this->assertSame('2026-08-07', $mv->created_at->toDateString()); // bukan 2026-08-11
        $this->assertSame(998, (int) $p->fresh()->hq_stock);              // saldo tetap benar
    }

    public function test_penerimaan_backdate_gerakan_stok_pakai_tanggal_terima(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');
        $p = $this->product();

        app(StockReceiptService::class)->receive(
            ['received_at' => '2026-08-05', 'supplier_name' => null, 'reference_no' => null, 'notes' => null],
            [['product_id' => $p->id, 'quantity' => 50, 'unit_cost' => 10000]]
        );

        $mv = StockMovement::where('reference_type', 'stock_receipt')->where('product_id', $p->id)->first();
        $this->assertNotNull($mv);
        $this->assertSame('2026-08-05', $mv->created_at->toDateString());
        $this->assertSame(50, (int) $p->fresh()->hq_stock);
    }
}
