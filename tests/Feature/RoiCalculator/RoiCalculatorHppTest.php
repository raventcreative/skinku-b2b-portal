<?php

namespace Tests\Feature\RoiCalculator;

use App\Models\Product;
use App\Models\RoiItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Aturan HPP di Kalkulator ROI: modal default = HPP produk, jadi modal, profit, BEP & target ROI hanya utk izin
 * Lihat HPP (default super admin). Admin tetap bisa pakai halaman (harga jual, potongan platform) tanpa angka modal.
 */
class RoiCalculatorHppTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create([
            'name' => $role, 'fullname' => strtoupper($role), 'username' => $role.uniqid(),
            'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'),
            'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_admin_tanpa_lihat_hpp_tidak_melihat_modal_profit_dan_target(): void
    {
        $p = Product::create(['name' => 'Sabun Wajah', 'sku' => 'SB-1', 'status' => 'active', 'cogs' => 13755]);
        RoiItem::create(['product_id' => $p->id, 'selling_price' => 39000, 'packing' => 2000]);

        $this->actingAs($this->user(User::ROLE_ADMIN))->get('/kalkulator-roi')->assertOk()
            ->assertSee('Sabun Wajah')->assertSee('Rp39.000')->assertSee('hanya tampil untuk izin Lihat HPP')
            ->assertDontSee('13.755')   // modal = HPP produk
            ->assertDontSee('12.908')   // profit bersih
            ->assertDontSee('3,02')     // BEP ROI
            ->assertDontSee('Target Min')
            ->assertDontSee('name="modal"', false);

        // Super admin (Lihat HPP) tetap melihat semuanya.
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN))->get('/kalkulator-roi')->assertOk()
            ->assertSee('Rp13.755')->assertSee('12.908')->assertSee('3,02')->assertSee('Target Min')
            ->assertSee('name="modal"', false)->assertDontSee('hanya tampil untuk izin Lihat HPP');
    }

    public function test_admin_tanpa_lihat_hpp_tidak_bisa_mengubah_modal(): void
    {
        $p = Product::create(['name' => 'Sabun', 'sku' => 'SB-1', 'status' => 'active', 'cogs' => 13755]);
        $item = RoiItem::create(['product_id' => $p->id, 'selling_price' => 39000, 'modal' => 12000]);

        // Modal dikirim paksa → diabaikan; harga jual tetap tersimpan.
        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->post("/kalkulator-roi/items/{$item->id}", ['selling_price' => 45000, 'modal' => 1])->assertRedirect();
        $this->assertSame([45000, 12000], [(int) $item->fresh()->selling_price, (int) $item->fresh()->modal]);

        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN))
            ->post("/kalkulator-roi/items/{$item->id}", ['selling_price' => 45000, 'modal' => 15000])->assertRedirect();
        $this->assertSame(15000, (int) $item->fresh()->modal);
    }
}
