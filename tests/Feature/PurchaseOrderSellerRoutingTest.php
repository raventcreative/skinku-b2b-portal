<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PurchaseOrderSellerRoutingTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $role, ?int $upline = null): User
    {
        $u = 'u'.(++$this->seq);

        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'company_name' => 'CV '.$u, 'role' => $role,
            'status' => User::STATUS_ACTIVE, 'upline_id' => $upline,
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'name' => 'P'.(++$this->seq), 'sku' => 'SKU-'.$this->seq,
            'price_distributor' => 24000, 'price_reseller' => 29000, 'price_retail' => 39000,
            'cogs' => 10000, 'hq_stock' => 100, 'status' => 'active',
        ]);
    }

    private function svc(): PurchaseOrderService
    {
        return app(PurchaseOrderService::class);
    }

    public function test_po_seller_id_adalah_upline_pembeli(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id); // upline = grand
        $p = $this->product();

        $po = $this->svc()->createForPartner($dist, [['product_id' => $p->id, 'qty' => 2]], null, null);

        $this->assertSame($grand->id, (int) $po->seller_id);
    }

    public function test_po_tanpa_upline_seller_null_hq(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR); // upline null
        $p = $this->product();

        $po = $this->svc()->createForPartner($grand, [['product_id' => $p->id, 'qty' => 1]], null, null);

        $this->assertNull($po->seller_id);
    }
}
