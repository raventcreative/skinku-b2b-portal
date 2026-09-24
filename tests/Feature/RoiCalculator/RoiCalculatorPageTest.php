<?php

namespace Tests\Feature\RoiCalculator;

use App\Models\Product;
use App\Models\RoiItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RoiCalculatorPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'admin', 'fullname' => 'Admin', 'username' => 'admin'.uniqid(),
            'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'),
            'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function reseller(): User
    {
        return User::create([
            'name' => 'r', 'fullname' => 'R', 'username' => 'r'.uniqid(),
            'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'),
            'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_halaman_menampilkan_hasil_hitung_untuk_baris_tersimpan(): void
    {
        $p = Product::create(['name' => 'Sabun Wajah', 'sku' => 'SB-1', 'status' => 'active', 'cogs' => 13755]);
        RoiItem::create(['product_id' => $p->id, 'selling_price' => 39000, 'packing' => 2000]);

        $this->actingAs($this->admin())
            ->get('/kalkulator-roi')
            ->assertOk()
            ->assertSee('Kalkulator ROI')
            ->assertSee('Sabun Wajah')
            ->assertSee('12.908')   // Profit Bersih (format ribuan Indonesia)
            ->assertSee('3,02');    // BEP ROI (2 desimal, koma)
    }

    public function test_empty_state_saat_belum_ada_baris(): void
    {
        $this->actingAs($this->admin())
            ->get('/kalkulator-roi')
            ->assertOk()
            ->assertSee('Belum ada produk');
    }

    public function test_reseller_ditolak(): void
    {
        $this->actingAs($this->reseller())
            ->get('/kalkulator-roi')
            ->assertForbidden();
    }

    public function test_menu_sidebar_tampil_sesuai_izin(): void
    {
        $this->actingAs($this->admin())->get('/dashboard')->assertOk()->assertSee('Kalkulator ROI');
        $this->actingAs($this->reseller())->get('/dashboard')->assertOk()->assertDontSee('Kalkulator ROI');
    }
}
