<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrandPriceForRoleTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function product(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Produk '.(++$this->seq), 'sku' => 'SKU-'.$this->seq,
            'price_distributor' => 24000, 'price_reseller' => 29000, 'price_retail' => 39000,
            'cogs' => 10000, 'hq_stock' => 100, 'status' => 'active',
        ], $overrides));
    }

    public function test_grand_pakai_price_grand_bila_terisi(): void
    {
        $p = $this->product(['price_grand' => 22000]);
        $this->assertEqualsWithDelta(22000, $p->priceForRole(User::ROLE_GRAND_DISTRIBUTOR), 0.01);
    }

    public function test_grand_fallback_ke_distributor_bila_price_grand_null(): void
    {
        $p = $this->product(['price_grand' => null]);
        // Fallback = price_distributor (24000), BUKAN 0, BUKAN retail.
        $this->assertEqualsWithDelta(24000, $p->priceForRole(User::ROLE_GRAND_DISTRIBUTOR), 0.01);
    }

    public function test_tier_lain_tidak_berubah(): void
    {
        $p = $this->product(['price_grand' => 22000]);
        $this->assertEqualsWithDelta(24000, $p->priceForRole(User::ROLE_DISTRIBUTOR), 0.01);
        $this->assertEqualsWithDelta(29000, $p->priceForRole(User::ROLE_RESELLER), 0.01);
        $this->assertEqualsWithDelta(29000, $p->priceForRole(User::ROLE_RESELLER_BRONZE), 0.01);
        $this->assertEqualsWithDelta(29000, $p->priceForRole(User::ROLE_RESELLER_GOLD), 0.01);
        $this->assertEqualsWithDelta(39000, $p->priceForRole('customer'), 0.01);
    }
}
