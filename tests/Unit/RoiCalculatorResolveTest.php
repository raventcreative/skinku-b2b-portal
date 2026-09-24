<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\RoiItem;
use App\Models\RoiSetting;
use App\Models\User;
use App\Services\RoiCalculatorService;
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

    private function svc(): RoiCalculatorService
    {
        return new RoiCalculatorService;
    }

    public function test_effective_inputs_pakai_cogs_saat_modal_null(): void
    {
        $p = Product::create(['name' => 'Sabun', 'sku' => 'SB-1', 'status' => 'active', 'cogs' => 13755]);
        $item = RoiItem::create(['product_id' => $p->id, 'selling_price' => 39000]); // modal null
        $s = RoiSetting::current();

        $in = $this->svc()->effectiveInputs($item, $s);

        $this->assertSame(13755, $in['modal']);         // dari COGS
        $this->assertSame(1000, $in['packing']);        // default global
        $this->assertSame(1250, $in['proses_order']);   // default global
        $this->assertSame(8.0, $in['admin_pct']);       // global
        $this->assertSame(650000, $in['komisi_cap']);   // global
    }

    public function test_override_baris_menang_atas_global(): void
    {
        $p = Product::create(['name' => 'Serum', 'sku' => 'SR-1', 'status' => 'active', 'cogs' => 20000]);
        $item = RoiItem::create([
            'product_id' => $p->id, 'selling_price' => 50000,
            'modal' => 18000, 'packing' => 3000, 'admin_pct' => 10, 'komisi_cap' => 100000,
        ]);
        $s = RoiSetting::current();

        $in = $this->svc()->effectiveInputs($item, $s);

        $this->assertSame(18000, $in['modal']);      // override, bukan COGS
        $this->assertSame(3000, $in['packing']);     // override
        $this->assertSame(10.0, $in['admin_pct']);   // override
        $this->assertSame(100000, $in['komisi_cap']); // override
        $this->assertSame(4.5, $in['voucher_pct']);  // tak di-override -> global
    }

    public function test_summary_merata_ratakan_dan_lewati_null(): void
    {
        $svc = $this->svc();
        $rows = [
            ['result' => ['avg_min' => 4.0, 'avg_optimum' => 6.0]],
            ['result' => ['avg_min' => 2.0, 'avg_optimum' => 8.0]],
            ['result' => ['avg_min' => null, 'avg_optimum' => null]], // rugi -> dilewati
        ];

        $sum = $svc->summary($rows);

        $this->assertSame(3.0, $sum['avg_min_all']);       // (4+2)/2
        $this->assertSame(7.0, $sum['avg_optimum_all']);   // (6+8)/2
    }

    public function test_row_for_merangkai_item_product_dan_result(): void
    {
        $p = Product::create(['name' => 'Sabun', 'sku' => 'SB-1', 'status' => 'active', 'cogs' => 13755]);
        $item = RoiItem::create(['product_id' => $p->id, 'selling_price' => 39000, 'packing' => 2000]);
        $row = $this->svc()->rowFor($item, RoiSetting::current());

        $this->assertSame($p->id, $row['product']->id);
        $this->assertSame(12908.0, $row['result']['profit_bersih']); // cocok Excel baris 7
    }
}
