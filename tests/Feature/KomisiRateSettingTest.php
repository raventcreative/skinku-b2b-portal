<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UI atur rate komisi di Pengaturan. Hanya rate AKTIF yang bisa diedit:
 * Override Grand + Bonus Join. Override Distributor/Reseller disembunyikan
 * (dorman — reseller beli offline, tak memicu override) dan TAK disentuh
 * saat simpan; key-nya tetap di nilai default backend.
 */
class KomisiRateSettingTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        return User::create([
            'name' => 'SA', 'username' => 'sa', 'email' => 'sa@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_simpan_rate_grand_dan_join(): void
    {
        $this->actingAs($this->superadmin())->post(route('settings.komisi.save'), [
            'grand' => 7, 'join' => 12,
        ])->assertRedirect();

        $this->assertSame('7', AppSetting::get('komisi_persen_grand_distributor'));
        $this->assertSame('12', AppSetting::get('komisi_persen_join'));
        // Key override dorman TAK disentuh oleh UI (tetap null / nilai default backend).
        $this->assertNull(AppSetting::get('komisi_persen_distributor'));
        $this->assertNull(AppSetting::get('komisi_persen_reseller_bronze'));
    }

    public function test_rate_di_luar_0_100_ditolak(): void
    {
        $this->actingAs($this->superadmin())->post(route('settings.komisi.save'), [
            'grand' => 150, 'join' => 12,
        ])->assertSessionHasErrors('grand');

        $this->assertNull(AppSetting::get('komisi_persen_grand_distributor'));
    }
}
