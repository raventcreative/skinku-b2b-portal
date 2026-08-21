<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DownlineOrderShowTest extends TestCase
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

    public function test_upline_lihat_detail_pesanan_downlinenya(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $po = $this->poFor($dist, $this->product());
        $this->actingAs($grand)->get(route('pesanan-downline.show', $po))->assertOk();
    }

    public function test_upline_ditolak_akses_p_o_hq(): void
    {
        // PO HQ = seller_id null. Guard seller_id===me otomatis gagal.
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $poHq = $this->poFor($grand, $this->product());       // grand beli dari HQ → seller null
        $this->actingAs($grand)->get(route('pesanan-downline.show', $poHq))->assertForbidden();
    }

    public function test_upline_ditolak_akses_pesanan_mitra_lain(): void
    {
        $grandA = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $distA = $this->user(User::ROLE_DISTRIBUTOR, $grandA->id);
        $poA = $this->poFor($distA, $this->product());         // seller_id = grandA
        $grandB = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $this->actingAs($grandB)->get(route('pesanan-downline.show', $poA))->assertForbidden();
    }
}
