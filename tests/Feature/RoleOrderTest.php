<?php

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_urutan_role_mengelompokkan_mlm(): void
    {
        $names = Role::ordered()->pluck('name')->all();
        $pos = array_flip($names);

        // HQ paling depan.
        $this->assertSame('super_admin', $names[0]);

        // Staf → non-MLM (KOL) → spine MLM.
        $this->assertLessThan($pos['kol_specialist'], $pos['gudang']);
        $this->assertLessThan($pos['grand_distributor'], $pos['kol_specialist']);

        // Spine MLM berurutan atas → bawah.
        $this->assertLessThan($pos['distributor'], $pos['grand_distributor']);
        $this->assertLessThan($pos['reseller_bronze'], $pos['distributor']);
        $this->assertLessThan($pos['reseller_gold'], $pos['reseller_bronze']);
    }
}
