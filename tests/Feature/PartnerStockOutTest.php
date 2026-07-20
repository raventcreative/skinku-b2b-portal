<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Distributor mencatat barang keluar dari stoknya sendiri.
 *
 * Mekanismenya sudah lama ada (endpoint partner-adjust), tapi dropdown-nya
 * menampilkan kode "OUT" mentah — mitra yang mencari "stok keluar" tak
 * menemukannya di balik istilah Inggris itu.
 */
class PartnerStockOutTest extends TestCase
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

    private function product(): Product
    {
        return Product::create([
            'name' => 'MIZU BODY WASH - 500ml', 'sku' => 'MZ-500ML', 'hq_stock' => 0,
            'status' => 'active', 'cogs' => 10_000, 'price_distributor' => 20_000, 'price_reseller' => 25_000,
        ]);
    }

    public function test_distributor_mencatat_barang_keluar_dari_stoknya(): void
    {
        $d = $this->distributor();
        $p = $this->product();
        Inventory::create(['user_id' => $d->id, 'product_id' => $p->id, 'quantity' => 50]);

        $this->actingAs($d)->post(route('inventory.partner-adjust'), [
            'user_id' => $d->id, 'product_id' => $p->id,
            'type' => StockMovement::TYPE_OUT, 'quantity' => 12, 'notes' => 'Jual ke pelanggan',
        ])->assertRedirect();

        $this->assertSame(38, (int) Inventory::where('user_id', $d->id)->value('quantity'));

        $m = StockMovement::where('user_id', $d->id)->first();
        $this->assertSame('OUT', $m->movement_type);
        $this->assertSame(50, (int) $m->before_qty);
        $this->assertSame(38, (int) $m->after_qty);
        $this->assertSame('Jual ke pelanggan', $m->notes);
    }

    public function test_tidak_bisa_mengeluarkan_lebih_dari_stok(): void
    {
        $d = $this->distributor();
        $p = $this->product();
        Inventory::create(['user_id' => $d->id, 'product_id' => $p->id, 'quantity' => 5]);

        $this->actingAs($d)->from(route('inventory.index'))->post(route('inventory.partner-adjust'), [
            'user_id' => $d->id, 'product_id' => $p->id,
            'type' => StockMovement::TYPE_OUT, 'quantity' => 10, 'notes' => 'Jual',
        ])->assertSessionHasErrors('quantity');

        $this->assertSame(5, (int) Inventory::where('user_id', $d->id)->value('quantity'));
        $this->assertSame(0, StockMovement::count());
    }

    public function test_distributor_tidak_bisa_menyesuaikan_stok_mitra_lain(): void
    {
        $a = $this->distributor('putu');
        $b = $this->distributor('wayan');
        $p = $this->product();
        Inventory::create(['user_id' => $b->id, 'product_id' => $p->id, 'quantity' => 50]);

        $this->actingAs($a)->post(route('inventory.partner-adjust'), [
            'user_id' => $b->id, 'product_id' => $p->id,
            'type' => StockMovement::TYPE_OUT, 'quantity' => 10, 'notes' => 'curang',
        ])->assertForbidden();

        $this->assertSame(50, (int) Inventory::where('user_id', $b->id)->value('quantity'));
    }

    public function test_halaman_stok_memakai_label_manusia_bukan_kode_mentah(): void
    {
        $d = $this->distributor();
        $p = $this->product();
        Inventory::create(['user_id' => $d->id, 'product_id' => $p->id, 'quantity' => 50]);

        $html = $this->actingAs($d)->get(route('inventory.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Barang Keluar', $html);
        // Value backend tetap OUT; yang tak boleh muncul adalah OUT sebagai LABEL.
        $this->assertStringContainsString('value="OUT"', $html);
        $this->assertStringContainsString('>Barang Keluar', $html);
        $this->assertStringNotContainsString('>OUT<', $html);
    }

    public function test_stok_kosong_menjelaskan_kenapa_dan_apa_yang_harus_dilakukan(): void
    {
        $d = $this->distributor();

        $this->actingAs($d)->get(route('inventory.index'))->assertOk()
            ->assertSee('Belum ada stok tercatat')
            ->assertSee('setelah PO Anda diselesaikan HQ');
    }
}
