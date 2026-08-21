<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\VolumeIncentiveTier;
use App\Services\VolumeIncentiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Insentif Volume Grand — model TOP-UP: hak = total belanja tahunan × rate-tier-
 * tertinggi; tiap evaluasi kasih SELISIH. Idempoten + append-only. Khusus GD.
 */
class VolumeIncentiveTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $role): User
    {
        $u = 'u'.(++$this->seq);

        return User::create(['name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@t.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE]);
    }

    private function tier(float $threshold, float $ratePercent): void
    {
        VolumeIncentiveTier::create(['threshold' => $threshold, 'rate_percent' => $ratePercent, 'is_active' => true]);
    }

    private function completedPo(User $grand, float $subtotal): PurchaseOrder
    {
        return PurchaseOrder::create([
            'po_number' => 'PO-'.(++$this->seq), 'created_by' => $grand->id, 'user_id' => $grand->id,
            'seller_id' => null, 'user_role' => $grand->role, 'status' => PurchaseOrder::STATUS_COMPLETED,
            'subtotal' => $subtotal, 'discount' => 0, 'shipping_cost' => 0, 'total_amount' => $subtotal,
            'payment_status' => PurchaseOrder::PAYMENT_UNPAID, 'completed_at' => Carbon::now(),
        ]);
    }

    private function svc(): VolumeIncentiveService
    {
        return app(VolumeIncentiveService::class);
    }

    private function volumeBalance(User $g): float
    {
        return (float) Commission::where('user_id', $g->id)->where('type', 'volume_bonus')->sum('amount');
    }

    public function test_top_up_telescoping_dan_naik_tier(): void
    {
        $this->tier(200_000_000, 5);
        $this->tier(500_000_000, 8);
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);

        // Belanja 250jt → 5% × 250jt = 12,5jt
        $this->svc()->evaluate($this->completedPo($grand, 250_000_000));
        $this->assertEqualsWithDelta(12_500_000, $this->volumeBalance($grand), 0.01);

        // Naik ke 300jt (tambah 50jt) → top-up = 300×5% − 12,5jt = 2,5jt (total 15jt)
        $this->svc()->evaluate($this->completedPo($grand, 50_000_000));
        $this->assertEqualsWithDelta(15_000_000, $this->volumeBalance($grand), 0.01);

        // Tembus 600jt (tambah 300jt) → tier 8%, entitlement 48jt, top-up = 48−15 = 33jt
        $this->svc()->evaluate($this->completedPo($grand, 300_000_000));
        $this->assertEqualsWithDelta(48_000_000, $this->volumeBalance($grand), 0.01); // 600×8%
    }

    public function test_idempoten_reeval_nol(): void
    {
        $this->tier(200_000_000, 5);
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $po = $this->completedPo($grand, 250_000_000);

        $this->svc()->evaluate($po);
        $before = $this->volumeBalance($grand);
        $this->svc()->evaluate($po); // evaluasi ulang PO sama
        $this->assertEqualsWithDelta($before, $this->volumeBalance($grand), 0.01); // tak dobel
    }

    public function test_dormant_nol_tier(): void
    {
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $this->svc()->evaluate($this->completedPo($grand, 999_000_000));
        $this->assertSame(0, Commission::where('type', 'volume_bonus')->count());
    }

    public function test_belum_tembus_tier_nol(): void
    {
        $this->tier(200_000_000, 5);
        $grand = $this->user(User::ROLE_GRAND_DISTRIBUTOR);
        $this->svc()->evaluate($this->completedPo($grand, 100_000_000)); // < 200jt
        $this->assertSame(0.0, $this->volumeBalance($grand));
    }

    public function test_hanya_gd_distributor_nol(): void
    {
        $this->tier(200_000_000, 5);
        $dist = $this->user(User::ROLE_DISTRIBUTOR);
        $this->svc()->evaluate($this->completedPo($dist, 300_000_000));
        $this->assertSame(0, Commission::where('type', 'volume_bonus')->count());
    }
}
