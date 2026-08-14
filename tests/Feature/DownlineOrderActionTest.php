<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DownlineOrderActionTest extends TestCase
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

    private function stock(User $u, Product $p, int $qty): void
    {
        Inventory::create(['user_id' => $u->id, 'product_id' => $p->id, 'quantity' => $qty]);
    }

    private function qty(User $u, Product $p): int
    {
        return (int) Inventory::where('user_id', $u->id)->where('product_id', $p->id)->value('quantity');
    }

    public function test_upline_verifikasi_bayar_downline(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $po = $this->poFor($dist, $this->product());
        $this->actingAs($grand)->post(route('pesanan-downline.verify-payment', $po), ['approve' => '1'])->assertRedirect();
        $po->refresh();
        $this->assertSame(PurchaseOrder::PAYMENT_PAID, $po->payment_status);
        $this->assertSame($grand->id, (int) $po->payment_verified_by);
    }

    public function test_upline_kirim_transfer_stok_upline_ke_downline(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $p = $this->product();
        $this->stock($grand, $p, 50);                         // upline punya stok
        $po = $this->poFor($dist, $p, 10);
        $this->actingAs($grand)->post(route('pesanan-downline.verify-payment', $po), ['approve' => '1']);
        $this->actingAs($grand)->post(route('pesanan-downline.fulfill', $po))->assertRedirect();
        $po->refresh();
        $this->assertSame(PurchaseOrder::STATUS_COMPLETED, $po->status);
        $this->assertSame(40, $this->qty($grand, $p));        // upline -10
        $this->assertSame(10, $this->qty($dist, $p));         // downline +10
    }

    public function test_kirim_sebelum_lunas_ditolak_stok_tak_berubah(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $p = $this->product();
        $this->stock($grand, $p, 50);
        $po = $this->poFor($dist, $p, 10);                    // belum lunas, bukan tempo
        $resp = $this->actingAs($grand)->post(route('pesanan-downline.fulfill', $po));
        $po->refresh();
        $this->assertNotSame(PurchaseOrder::STATUS_COMPLETED, $po->status); // gate bayar menahan
        $this->assertSame(50, $this->qty($grand, $p));        // stok tak berubah
    }

    public function test_upline_tolak_pesanan_dengan_alasan(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $po = $this->poFor($dist, $this->product());
        $this->actingAs($grand)->post(route('pesanan-downline.reject', $po), ['reason' => 'Stok habis'])->assertRedirect();
        $po->refresh();
        $this->assertSame(PurchaseOrder::STATUS_CANCELLED, $po->status);
    }

    public function test_aksi_di_pesanan_mitra_lain_ditolak_403(): void
    {
        $grandA = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $distA = $this->user(User::ROLE_DISTRIBUTOR, $grandA->id);
        $po = $this->poFor($distA, $this->product());         // seller_id = grandA
        $grandB = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $this->actingAs($grandB)->post(route('pesanan-downline.fulfill', $po))->assertForbidden();
        $this->actingAs($grandB)->post(route('pesanan-downline.verify-payment', $po), ['approve' => '1'])->assertForbidden();
        $this->actingAs($grandB)->post(route('pesanan-downline.reject', $po), ['reason' => 'x'])->assertForbidden();
    }
}
