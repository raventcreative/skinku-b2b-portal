<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UI atur rate komisi di Pengaturan. Model A: OVERRIDE dinonaktifkan (dorman,
 * rate 0) → hanya Bonus Join yang bisa diedit. Key override TAK disentuh dari
 * UI (tetap dorman, revivable).
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

    public function test_simpan_rate_join(): void
    {
        $this->actingAs($this->superadmin())->post(route('settings.komisi.save'), [
            'join' => 12,
        ])->assertRedirect();

        $this->assertSame('12', AppSetting::get('komisi_persen_join'));
        // Model A: override dinonaktifkan → key override TAK ditulis dari UI (tetap dorman).
        $this->assertNull(AppSetting::get('komisi_persen_grand_distributor'));
    }

    public function test_rate_di_luar_0_100_ditolak(): void
    {
        $this->actingAs($this->superadmin())->post(route('settings.komisi.save'), [
            'join' => 150,
        ])->assertSessionHasErrors('join');

        $this->assertNull(AppSetting::get('komisi_persen_join'));
    }
}
