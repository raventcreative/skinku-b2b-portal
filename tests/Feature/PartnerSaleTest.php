<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Inventory;
use App\Models\PartnerSale;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Penjualan mitra ke customer akhir (barang keluar bentuk nota, 1 customer
 * banyak produk). Menurunkan stok mitra, atomik: gagal satu baris → batal semua.
 */
class PartnerSaleTest extends TestCase
{
    use RefreshDatabase;

    private function distributor(string $u = 'putu'): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'company_name' => 'SKINKU BALI',
            'role' => User::ROLE_DISTRIBUTOR, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function product(string $name, string $sku, int $retail = 25000): Product
    {
        return Product::create([
            'name' => $name, 'sku' => $sku, 'hq_stock' => 0, 'status' => 'active',
            'cogs' => 10_000, 'price_distributor' => 20_000, 'price_reseller' => 22_000, 'price_retail' => $retail,
        ]);
    }

    private function stock(User $u, Product $p, int $qty): void
    {
        Inventory::create(['user_id' => $u->id, 'product_id' => $p->id, 'quantity' => $qty]);
    }

    public function test_mencatat_penjualan_banyak_produk_dan_memotong_stok(): void
    {
        Carbon::setTestNow('2026-07-20 10:00:00');
        $d = $this->distributor();
        $mizu = $this->product('MIZU BODY WASH - 500ml', 'MZ', 25_000);
        $soap = $this->product('Body Soap - 80g', 'BS', 15_000);
        $this->stock($d, $mizu, 50);
        $this->stock($d, $soap, 30);

        $this->actingAs($d)->post(route('partner-sales.store'), [
            'customer_name' => 'Toko Budi',
            'sold_at' => '2026-07-20',
            'items' => [
                ['product_id' => $mizu->id, 'qty' => 5, 'price' => 25_000],
                ['product_id' => $soap->id, 'qty' => 3, 'price' => 15_000],
            ],
        ])->assertRedirect(route('partner-sales.index'));

        // Stok tiap produk berkurang.
        $this->assertSame(45, (int) Inventory::where('user_id', $d->id)->where('product_id', $mizu->id)->value('quantity'));
        $this->assertSame(27, (int) Inventory::where('user_id', $d->id)->where('product_id', $soap->id)->value('quantity'));

        // Nota tercatat: total 5×25000 + 3×15000 = 170000.
        $sale = PartnerSale::first();
        $this->assertSame('Toko Budi', $sale->customer_name);
        $this->assertEqualsWithDelta(170_000, (float) $sale->total_amount, 0.01);
        $this->assertCount(2, $sale->items);

        // Gerakan OUT mereferensi nota.
        $this->assertSame(2, StockMovement::where('reference_type', 'partner_sale')->where('reference_id', $sale->id)->count());
    }

    public function test_oversell_membatalkan_seluruh_nota(): void
    {
        $d = $this->distributor();
        $mizu = $this->product('MIZU', 'MZ');
        $soap = $this->product('Soap', 'BS');
        $this->stock($d, $mizu, 50);
        $this->stock($d, $soap, 2);   // cuma 2

        // Baris kedua minta 3 dari 2 → seluruh nota batal, termasuk baris pertama.
        $this->actingAs($d)->from(route('partner-sales.index'))->post(route('partner-sales.store'), [
            'sold_at' => '2026-07-20',
            'items' => [
                ['product_id' => $mizu->id, 'qty' => 5, 'price' => 25_000],
                ['product_id' => $soap->id, 'qty' => 3, 'price' => 15_000],
            ],
        ])->assertSessionHasErrors('items');

        // TIDAK ADA yang berubah — nota separuh lebih berbahaya daripada gagal total.
        $this->assertSame(50, (int) Inventory::where('user_id', $d->id)->where('product_id', $mizu->id)->value('quantity'));
        $this->assertSame(2, (int) Inventory::where('user_id', $d->id)->where('product_id', $soap->id)->value('quantity'));
        $this->assertSame(0, PartnerSale::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_total_dihitung_server_bukan_dari_klien(): void
    {
        $d = $this->distributor();
        $mizu = $this->product('MIZU', 'MZ');
        $this->stock($d, $mizu, 50);

        // Klien tak mengirim total; server menghitung 4×30000 = 120000.
        $this->actingAs($d)->post(route('partner-sales.store'), [
            'sold_at' => '2026-07-20',
            'items' => [['product_id' => $mizu->id, 'qty' => 4, 'price' => 30_000]],
        ]);

        $this->assertEqualsWithDelta(120_000, (float) PartnerSale::first()->total_amount, 0.01);
    }

    public function test_penjualan_tercatat_di_audit_log(): void
    {
        $d = $this->distributor();
        $mizu = $this->product('MIZU', 'MZ');
        $this->stock($d, $mizu, 50);

        $this->actingAs($d)->post(route('partner-sales.store'), [
            'customer_name' => 'Toko Budi', 'sold_at' => '2026-07-20',
            'items' => [['product_id' => $mizu->id, 'qty' => 5, 'price' => 25_000]],
        ]);

        $log = AuditLog::where('action', 'partner_sale_record')->first();
        $this->assertNotNull($log);
        $this->assertSame($d->id, (int) $log->performed_by);
        $this->assertSame('Toko Budi', $log->after_data['customer']);
    }

    public function test_mitra_hanya_melihat_penjualannya_sendiri(): void
    {
        $a = $this->distributor('putu');
        $b = $this->distributor('wayan');
        $mizu = $this->product('MIZU', 'MZ');
        $this->stock($a, $mizu, 50);
        $this->stock($b, $mizu, 50);

        $this->actingAs($a)->post(route('partner-sales.store'), [
            'customer_name' => 'Punya Putu', 'sold_at' => '2026-07-20',
            'items' => [['product_id' => $mizu->id, 'qty' => 1, 'price' => 25_000]],
        ]);
        $this->actingAs($b)->post(route('partner-sales.store'), [
            'customer_name' => 'Punya Wayan', 'sold_at' => '2026-07-20',
            'items' => [['product_id' => $mizu->id, 'qty' => 1, 'price' => 25_000]],
        ]);

        $this->actingAs($a)->get(route('partner-sales.index'))->assertOk()
            ->assertSee('Punya Putu')->assertDontSee('Punya Wayan');
    }

    public function test_staf_bukan_mitra_tidak_bisa_akses(): void
    {
        $admin = User::create([
            'name' => 'A', 'fullname' => 'Admin', 'username' => 'adm', 'email' => 'adm@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);

        $this->actingAs($admin)->get(route('partner-sales.index'))->assertForbidden();
    }

    public function test_nota_tanpa_item_ditolak(): void
    {
        $d = $this->distributor();

        $this->actingAs($d)->from(route('partner-sales.index'))->post(route('partner-sales.store'), [
            'sold_at' => '2026-07-20', 'items' => [['product_id' => null, 'qty' => 0, 'price' => 0]],
        ])->assertSessionHasErrors();

        $this->assertSame(0, PartnerSale::count());
    }
}
