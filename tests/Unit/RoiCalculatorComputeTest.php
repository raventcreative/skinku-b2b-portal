<?php

namespace Tests\Unit;

use App\Services\RoiCalculatorService;
use Tests\TestCase;

class RoiCalculatorComputeTest extends TestCase
{
    private function svc(): RoiCalculatorService
    {
        return new RoiCalculatorService;
    }

    /** Acuan Excel baris 7 (Sabun). */
    private function row7(): array
    {
        return [
            'selling_price' => 39000, 'modal' => 13755, 'packing' => 2000, 'proses_order' => 1250,
            'admin_pct' => 8, 'voucher_pct' => 4.5, 'komisi_pct' => 5.5, 'komisi_cap' => 650000,
            'mall_pct' => 1.8, 'pajak_pct' => 0.5, 'operasional_pct' => 3, 'affiliate_pct' => 5,
        ];
    }

    public function test_biaya_dan_profit_cocok_dengan_excel_baris_7(): void
    {
        $r = $this->svc()->compute($this->row7());

        $this->assertSame(25245.0, $r['profit']);
        $this->assertEqualsWithDelta(3120.0, $r['admin'], 0.01);
        $this->assertEqualsWithDelta(1755.0, $r['voucher'], 0.01);
        $this->assertEqualsWithDelta(2145.0, $r['komisi'], 0.01);
        $this->assertEqualsWithDelta(702.0, $r['mall'], 0.01);
        $this->assertEqualsWithDelta(195.0, $r['pajak'], 0.01);
        $this->assertEqualsWithDelta(1170.0, $r['operasional'], 0.01);
        // Total Biaya = SUM(G:M) termasuk proses order (1250).
        $this->assertEqualsWithDelta(10337.0, $r['total_biaya'], 0.01);
        // Profit Bersih = Harga - Modal - Packing - Total Biaya.
        $this->assertEqualsWithDelta(12908.0, $r['profit_bersih'], 0.01);
        $this->assertEqualsWithDelta(3.0213, $r['bep_roi'], 0.001);
    }

    public function test_target_roi_dan_rata_rata(): void
    {
        $r = $this->svc()->compute($this->row7());

        // affiliate 5% * 39000 = 1950 -> profit_after_aff = 12908 - 1950 = 10958
        $this->assertSame(1950.0, $r['affiliate']);
        $this->assertSame(10958.0, $r['profit_after_aff']);
        // Target 10% tanpa aff = 39000 / (12908 - 3900) = 39000/9008
        $this->assertEqualsWithDelta(39000 / 9008, $r['target_noaff'][10], 0.001);
        // Target 10% dgn aff = 39000 / (10958 - 3900) = 39000/7058
        $this->assertEqualsWithDelta(39000 / 7058, $r['target_aff'][10], 0.001);
        // Rata2 Min (10%) = (target_noaff[10] + target_aff[10]) / 2
        $this->assertEqualsWithDelta(((39000 / 9008) + (39000 / 7058)) / 2, $r['avg_min'], 0.001);
    }

    public function test_komisi_kena_cap(): void
    {
        $in = $this->row7();
        $in['selling_price'] = 20000000; // 5.5% = 1.100.000 > cap 650.000
        $r = $this->svc()->compute($in);

        $this->assertSame(650000.0, $r['komisi']);
    }

    public function test_cap_nol_berarti_tanpa_cap(): void
    {
        $in = $this->row7();
        $in['selling_price'] = 20000000;
        $in['komisi_cap'] = 0;
        $r = $this->svc()->compute($in);

        $this->assertSame(1100000.0, $r['komisi']); // 5.5% * 20jt
    }

    public function test_profit_bersih_negatif_membuat_roi_null(): void
    {
        $in = $this->row7();
        $in['modal'] = 39000; // profit 0 -> profit_bersih negatif
        $r = $this->svc()->compute($in);

        $this->assertTrue($r['profit_bersih'] < 0);
        $this->assertNull($r['bep_roi']);
        $this->assertNull($r['target_noaff'][10]);
        $this->assertNull($r['avg_min']);
    }

    public function test_target_null_saat_penyebut_habis_walau_profit_positif(): void
    {
        // profit_bersih kecil, x*price besar -> penyebut <= 0 -> null utk target itu.
        $in = [
            'selling_price' => 10000, 'modal' => 8000, 'packing' => 0, 'proses_order' => 0,
            'admin_pct' => 0, 'voucher_pct' => 0, 'komisi_pct' => 0, 'komisi_cap' => 0,
            'mall_pct' => 0, 'pajak_pct' => 0, 'operasional_pct' => 0, 'affiliate_pct' => 0,
        ];
        $r = $this->svc()->compute($in);

        $this->assertSame(2000.0, $r['profit_bersih']);      // 10000-8000
        $this->assertEqualsWithDelta(5.0, $r['bep_roi'], 0.0001); // 10000/2000
        // Target 20% -> penyebut = 2000 - 2000 = 0 -> null
        $this->assertNull($r['target_noaff'][20]);
    }
}
