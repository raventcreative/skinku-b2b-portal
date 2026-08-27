<?php

namespace Tests\Feature;

use App\Services\KolScoringService;
use App\Support\KolMetrics;
use Tests\TestCase;

/** APS + KSS + metrics — port dari Iyuro, angka harus persis. Tanpa DB. */
class KolScoringTest extends TestCase
{
    private function svc(): KolScoringService
    {
        return new KolScoringService;
    }

    public function test_metrics_dasar(): void
    {
        $this->assertSame(5000.0, KolMetrics::ecpm(5_000_000, 1_000_000));   // (5jt/1jt)*1000
        $this->assertNull(KolMetrics::rpm(100, 0));                          // views 0
        $this->assertSame(2.0, KolMetrics::roas(200, 100));
        $this->assertSame(3000.0, KolMetrics::median([1000, 3000, 5000]));
        $this->assertEqualsWithDelta(44.44, KolMetrics::growthVelocity([1_000_000, 1_500_000, 2_000_000, 3_000_000]), 0.1);
        $c = KolMetrics::consistency([3, 3, 0, 0]);
        $this->assertTrue($c['hasTwoWeekGap']);
    }

    public function test_aps_new_bila_kurang_4_minggu(): void
    {
        $r = $this->svc()->aps(['weeklyGmv' => [100, 200, 300], 'weeklyContent' => [1, 1, 1], 'weeksOfData' => 3, 'monthGmv' => 500_000, 'monthViews' => 10_000]);
        $this->assertSame('new', $r['status']);
        $this->assertNull($r['score']);
    }

    public function test_aps_scored_bina_intensif(): void
    {
        // growth avg 44,4%→80 · rpm 50rb→70 · konsistensi penuh→100 · skala 5jt→100
        // = .35*80 + .25*70 + .2*100 + .2*100 = 85,5
        $r = $this->svc()->aps([
            'weeklyGmv' => [1_000_000, 1_500_000, 2_000_000, 3_000_000],
            'weeklyContent' => [3, 3, 3, 3], 'weeksOfData' => 4, 'monthGmv' => 5_000_000, 'monthViews' => 100_000,
        ]);
        $this->assertSame(85.5, $r['score']);
        $this->assertSame('bina_intensif', $r['label']);
        $this->assertFalse($r['capped']);
    }

    public function test_aps_cap_40_bila_2_minggu_tak_posting_dan_reweight_rpm_null(): void
    {
        // rpm null (monthViews null) → bobot dialihkan; skor >40 tapi 2 minggu terakhir 0 → cap 40.
        $r = $this->svc()->aps([
            'weeklyGmv' => [1_000_000, 2_000_000, 4_000_000, 8_000_000],
            'weeklyContent' => [3, 3, 0, 0], 'weeksOfData' => 4, 'monthGmv' => 8_000_000, 'monthViews' => null,
        ]);
        $this->assertSame(40.0, $r['score']);
        $this->assertTrue($r['capped']);
        $this->assertSame('nurture', $r['label']);
    }

    public function test_kss_barter_shortlist(): void
    {
        // barter→90 · er 10→100 · niche 100 · history 100 · readiness 100 = 96,5
        $r = $this->svc()->kss(['rate' => 0, 'barterOnly' => true, 'medianViews' => 50_000, 'engagementRate' => 10.0, 'niche' => 'beauty_majority', 'history' => 'good', 'readiness' => 'active']);
        $this->assertSame(96.5, $r['score']);
        $this->assertSame('shortlist', $r['decision']);
    }

    public function test_kss_tolak(): void
    {
        // ecpm 500rb→0 · er 1→0 · niche 0 · history bad 10 · readiness none 20 = .15*10 + .1*20 = 3,5
        $r = $this->svc()->kss(['rate' => 5_000_000, 'barterOnly' => false, 'medianViews' => 10_000, 'engagementRate' => 1.0, 'niche' => 'irrelevant', 'history' => 'bad', 'readiness' => 'none']);
        $this->assertSame(3.5, $r['score']);
        $this->assertSame('tolak', $r['decision']);
    }
}
