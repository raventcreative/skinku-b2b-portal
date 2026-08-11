<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Support\GrandPriceList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrandPriceBySkuTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function product(string $sku): Product
    {
        return Product::create([
            'name' => 'Produk '.(++$this->seq), 'sku' => $sku,
            'price_distributor' => 29000, 'price_reseller' => 38000, 'price_retail' => 65000,
            'cogs' => 14000, 'hq_stock' => 0, 'status' => 'active',
        ]);
    }

    public function test_apply_by_sku_isi_price_grand_untuk_sku_dikenal(): void
    {
        $mizu = $this->product('MZ-500ML');
        $soap = $this->product('SOAP-1');
        $unknown = $this->product('XYZ-999');

        GrandPriceList::applyBySku();

        $this->assertEqualsWithDelta(26000, (float) $mizu->fresh()->price_grand, 0.01);
        $this->assertEqualsWithDelta(22000, (float) $soap->fresh()->price_grand, 0.01);
        $this->assertNull($unknown->fresh()->price_grand);
    }
}
