<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class InterPartnerFulfillmentTest extends TestCase
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

    private function product(int $hqStock = 100): Product
    {
        return Product::create([
            'name' => 'P'.(++$this->seq), 'sku' => 'SKU-'.$this->seq,
            'price_distributor' => 24000, 'price_reseller' => 29000, 'price_retail' => 39000,
            'cogs' => 10000, 'hq_stock' => $hqStock, 'status' => 'active',
        ]);
    }

    private function stock(User $u, Product $p, int $qty): void
    {
        Inventory::create(['user_id' => $u->id, 'product_id' => $p->id, 'quantity' => $qty]);
    }

    private function svc(): PurchaseOrderService
    {
        return app(PurchaseOrderService::class);
    }

    private function qty(User $u, Product $p): int
    {
        return (int) Inventory::where('user_id', $u->id)->where('product_id', $p->id)->value('quantity');
    }

    public function test_seller_mitra_potong_stok_upline_tambah_downline(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $p = $this->product(hqStock: 100);
        $this->stock($grand, $p, 50); // upline punya stok

        $po = $this->svc()->createForPartner($dist, [['product_id' => $p->id, 'qty' => 10]], null, null);
        $this->svc()->complete($po);

        $this->assertSame(40, $this->qty($grand, $p));   // upline turun 10
        $this->assertSame(10, $this->qty($dist, $p));    // downline naik 10
        $this->assertSame(100, (int) $p->fresh()->hq_stock); // stok HQ TAK tersentuh
    }

    public function test_stok_upline_kurang_complete_gagal_dan_rollback(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, $grand->id);
        $p = $this->product();
        $this->stock($grand, $p, 5); // cuma 5

        $po = $this->svc()->createForPartner($dist, [['product_id' => $p->id, 'qty' => 10]], null, null);

        try {
            $this->svc()->complete($po);
            $this->fail('Seharusnya melempar karena stok upline kurang.');
        } catch (RuntimeException $e) {
            // diharapkan
        }

        $this->assertSame(5, $this->qty($grand, $p));                 // tak berubah
        $this->assertSame(0, $this->qty($dist, $p));                  // tak nambah
        $this->assertNull($po->fresh()->completed_at);                // PO tak jadi selesai
    }

    public function test_seller_hq_tetap_potong_stok_hq_regresi(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR); // upline null → seller HQ
        $p = $this->product(hqStock: 100);

        $po = $this->svc()->createForPartner($grand, [['product_id' => $p->id, 'qty' => 10]], null, null);
        $this->svc()->complete($po);

        $this->assertSame(90, (int) $p->fresh()->hq_stock);  // HQ turun 10 (jalur existing)
        $this->assertSame(10, $this->qty($grand, $p));       // pembeli naik 10
    }
}
