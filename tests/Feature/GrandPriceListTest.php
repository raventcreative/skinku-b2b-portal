<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Support\GrandPriceList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrandPriceListTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function product(string $name, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => $name, 'sku' => 'SKU-'.(++$this->seq),
            'price_distributor' => 24000, 'price_reseller' => 29000, 'price_retail' => 39000,
            'cogs' => 10000, 'hq_stock' => 100, 'status' => 'active',
        ], $overrides));
    }

    public function test_apply_isi_price_grand_untuk_nama_yang_cocok(): void
    {
        $faceMist = $this->product('Face Mist', ['price_distributor' => 15000]);
        $unknown = $this->product('Produk Antah Berantah');

        GrandPriceList::apply();

        $this->assertEqualsWithDelta(13500, (float) $faceMist->fresh()->price_grand, 0.01);
        $this->assertNull($unknown->fresh()->price_grand);
    }

    public function test_apply_cocok_case_insensitive_dan_trim(): void
    {
        $p = $this->product('  NIGHT CREAM  ');

        GrandPriceList::apply();

        $this->assertEqualsWithDelta(41000, (float) $p->fresh()->price_grand, 0.01);
    }
}
