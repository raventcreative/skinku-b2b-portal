<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DownlineOrderListTest extends TestCase
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
            'price_distributor' => 20000, 'price_reseller' => 25000, 'price_retail' => 39000,
            'cogs' => 10000, 'hq_stock' => 1000, 'status' => 'active',
        ]);
    }

    private function poFor(User $buyer, Product $p, int $qty = 5)
    {
        return app(PurchaseOrderService::class)->createForPartner($buyer, [['product_id' => $p->id, 'qty' => $qty]], null, null);
    }

    public function test_upline_lihat_pesanan_downlinenya_saja(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);      // downline si grand
        $p = $this->product();
        $poDownline = $this->poFor($dist, $p);                        // seller_id = grand
        $poHq = $this->poFor($grand, $p);                             // grand beli dari HQ → seller_id null

        $resp = $this->actingAs($grand)->get(route('pesanan-downline.index'));
        $resp->assertOk();
        $resp->assertSee($poDownline->po_number);   // pesanan downline muncul
        $resp->assertDontSee($poHq->po_number);      // PO HQ (dia sbagai pembeli) TIDAK muncul di sini
    }

    public function test_upline_lain_tak_lihat_pesanan_bukan_downlinenya(): void
    {
        $grandA = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $distA = $this->user(User::ROLE_DISTRIBUTOR, $grandA->id);
        $grandB = $this->user(User::ROLE_GRAND_DISTRIBUTOR);          // mitra lain, tak berelasi
        $p = $this->product();
        $poA = $this->poFor($distA, $p);                              // seller_id = grandA

        $resp = $this->actingAs($grandB)->get(route('pesanan-downline.index'));
        $resp->assertOk();
        $resp->assertDontSee($poA->po_number);                        // grandB tak lihat pesanan grandA
    }
}
