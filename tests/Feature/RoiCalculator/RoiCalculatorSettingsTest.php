<?php

namespace Tests\Feature\RoiCalculator;

use App\Models\RoiSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RoiCalculatorSettingsTest extends TestCase
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

    private function payload(array $override = []): array
    {
        return array_merge([
            'admin_pct' => 8, 'voucher_pct' => 4.5, 'komisi_pct' => 5.5, 'komisi_cap' => 650000,
            'mall_pct' => 1.8, 'pajak_pct' => 0.5, 'operasional_pct' => 3, 'affiliate_pct' => 5,
            'packing_default' => 1000, 'proses_order_default' => 1250,
        ], $override);
    }

    public function test_simpan_setelan_memperbarui_baris_current(): void
    {
        $this->actingAs($this->admin())
            ->post('/kalkulator-roi/settings', $this->payload(['admin_pct' => 9.25, 'komisi_cap' => 700000]))
            ->assertRedirect()
            ->assertSessionHas('status');

        $s = RoiSetting::current();
        $this->assertSame(9.25, $s->admin_pct);
        $this->assertSame(700000, $s->komisi_cap);
        $this->assertSame(1, RoiSetting::count());
    }

    public function test_menolak_persen_negatif(): void
    {
        $this->actingAs($this->admin())
            ->post('/kalkulator-roi/settings', $this->payload(['admin_pct' => -1]))
            ->assertSessionHasErrors('admin_pct');
    }

    public function test_form_menampilkan_nilai_saat_ini(): void
    {
        RoiSetting::current()->update(['operasional_pct' => 2.75]);

        $this->actingAs($this->admin())
            ->get('/kalkulator-roi')
            ->assertOk()
            ->assertSee('Setelan Biaya')
            ->assertSee('2.75'); // value input (float, titik desimal di atribut value)
    }
}
