<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class GrandPoPriceTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $role): User
    {
        $u = 'u'.(++$this->seq);

        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'company_name' => 'CV '.$u, 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function product(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Produk '.(++$this->seq), 'sku' => 'SKU-'.$this->seq,
            'price_distributor' => 24000, 'price_reseller' => 29000, 'price_retail' => 39000,
            'cogs' => 10000, 'hq_stock' => 100, 'status' => 'active',
        ], $overrides));
    }

    private function svc(): PurchaseOrderService
    {
        return app(PurchaseOrderService::class);
    }

    public function test_po_grand_pakai_price_grand(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product(['price_grand' => 22000]);

        $po = $this->svc()->createForPartner($grand, [['product_id' => $p->id, 'qty' => 2]], null, null);

        $this->assertEqualsWithDelta(22000, (float) $po->items->first()->unit_price, 0.01);
        $this->assertEqualsWithDelta(44000, (float) $po->total_amount, 0.01);
    }

    public function test_po_grand_fallback_distributor_bila_price_grand_null(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product(['price_grand' => null]);

        $po = $this->svc()->createForPartner($grand, [['product_id' => $p->id, 'qty' => 1]], null, null);

        // Fallback distributor (24000), BUKAN 0.
        $this->assertEqualsWithDelta(24000, (float) $po->items->first()->unit_price, 0.01);
    }

    public function test_po_distributor_tidak_berubah(): void
    {
        $dist = $this->user(User::ROLE_DISTRIBUTOR);
        $p = $this->product(['price_grand' => 22000]);

        $po = $this->svc()->createForPartner($dist, [['product_id' => $p->id, 'qty' => 1]], null, null);

        $this->assertEqualsWithDelta(24000, (float) $po->items->first()->unit_price, 0.01);
    }

    public function test_price_override_tetap_menang(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $p = $this->product(['price_grand' => 22000]);

        $po = $this->svc()->createForPartner($grand, [['product_id' => $p->id, 'qty' => 1]], null, null, [$p->id => 20000]);

        $this->assertEqualsWithDelta(20000, (float) $po->items->first()->unit_price, 0.01);
    }
}
