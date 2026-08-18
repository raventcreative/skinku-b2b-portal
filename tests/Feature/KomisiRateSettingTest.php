<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 3: UI atur rate komisi di Pengaturan. Satu input "Reseller" harus
 * menyinkronkan ketiga key reseller (_bronze, _gold, legacy) supaya engine
 * (CommissionService::overrideRate) tak diam-diam jatuh ke default hardcoded.
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

    public function test_simpan_rate_tulis_semua_key_reseller_sinkron(): void
    {
        $this->actingAs($this->superadmin())->post(route('settings.komisi.save'), [
            'grand' => 7, 'distributor' => 5, 'reseller' => 3, 'join' => 12,
        ])->assertRedirect();

        $this->assertSame('7', AppSetting::get('komisi_persen_grand_distributor'));
        $this->assertSame('5', AppSetting::get('komisi_persen_distributor'));
        $this->assertSame('3', AppSetting::get('komisi_persen_reseller_bronze'));
        $this->assertSame('3', AppSetting::get('komisi_persen_reseller_gold'));
        $this->assertSame('3', AppSetting::get('komisi_persen_reseller'));
        $this->assertSame('12', AppSetting::get('komisi_persen_join'));
    }

    public function test_rate_di_luar_0_100_ditolak(): void
    {
        $this->actingAs($this->superadmin())->post(route('settings.komisi.save'), [
            'grand' => 150, 'distributor' => 5, 'reseller' => 3, 'join' => 12,
        ])->assertSessionHasErrors('grand');

        $this->assertNull(AppSetting::get('komisi_persen_grand_distributor'));
    }
}
