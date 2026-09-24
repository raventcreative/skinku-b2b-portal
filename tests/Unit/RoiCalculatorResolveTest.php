<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\RoiItem;
use App\Models\RoiSetting;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoiCalculatorResolveTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_membuat_baris_setelan_dengan_default(): void
    {
        $s = RoiSetting::current();

        $this->assertSame(1, $s->id);
        $this->assertSame(8.0, $s->admin_pct);
        $this->assertSame(650000, $s->komisi_cap);
        $this->assertSame(1250, $s->proses_order_default);
        // Panggilan kedua tidak membuat baris baru.
        RoiSetting::current();
        $this->assertSame(1, RoiSetting::count());
    }

    public function test_item_punya_relasi_product(): void
    {
        $p = Product::create(['name' => 'Sabun', 'sku' => 'SB-1', 'status' => 'active', 'cogs' => 13755]);
        $item = RoiItem::create(['product_id' => $p->id, 'selling_price' => 39000]);

        $this->assertSame('Sabun', $item->product->name);
        $this->assertNull($item->modal);        // nullable -> ikut COGS nanti
        $this->assertNull($item->admin_pct);    // nullable -> ikut global nanti
    }

    public function test_izin_default_admin_saja(): void
    {
        $this->assertTrue(Permissions::roleHas(User::ROLE_ADMIN, 'manage_roi_calculator'));
        $this->assertTrue(Permissions::roleHas(User::ROLE_SUPER_ADMIN, 'manage_roi_calculator'));
        $this->assertFalse(Permissions::roleHas(User::ROLE_RESELLER, 'manage_roi_calculator'));
    }
}
