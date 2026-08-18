<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\JoinPackage;
use App\Models\JoinTransaction;
use App\Models\Product;
use App\Models\User;
use App\Services\OnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class OnboardingServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(string $role): User
    {
        $u = 'u'.(++$this->seq);

        return User::create(['name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@t.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE]);
    }

    private function product(int $stock): Product
    {
        return Product::create(['name' => 'P'.(++$this->seq), 'sku' => 'SKU-'.$this->seq,
            'price_distributor' => 24000, 'price_reseller' => 29000, 'price_retail' => 39000,
            'cogs' => 10000, 'hq_stock' => $stock, 'status' => Product::STATUS_ACTIVE]);
    }

    private function paket(Product $p, int $qty, int $stockNeededPrice = 149000): JoinPackage
    {
        $paket = JoinPackage::create(['name' => 'Bronze', 'target_role' => User::ROLE_RESELLER_BRONZE,
            'price' => $stockNeededPrice, 'is_active' => true]);
        $paket->items()->create(['product_id' => $p->id, 'qty' => $qty]);

        return $paket;
    }

    private function data(): array
    {
        $n = ++$this->seq;

        return ['fullname' => 'Reseller '.$n, 'email' => "res{$n}@t.test", 'username' => "res{$n}",
            'password' => 'secret123', 'company_name' => 'CV R'.$n];
    }

    public function test_onboard_sukses_potong_stok_dan_bonus(): void
    {
        $admin = $this->user(User::ROLE_SUPER_ADMIN);
        $upline = $this->user(User::ROLE_DISTRIBUTOR);
        $p = $this->product(100);
        $paket = $this->paket($p, 3); // 3 unit per join

        $reseller = app(OnboardingService::class)->onboard($this->data(), $paket, $upline->id, $admin->id);

        // Reseller dibuat, role bronze, upline benar
        $this->assertSame(User::ROLE_RESELLER_BRONZE, $reseller->role);
        $this->assertSame($upline->id, $reseller->upline_id);
        // Stok HQ turun 3
        $this->assertSame(97, (int) $p->fresh()->hq_stock);
        // Transaksi paket tercatat
        $this->assertSame(1, JoinTransaction::where('user_id', $reseller->id)->count());
        // Bonus join 10% dari 149rb = 14.900 ke upline
        $this->assertEqualsWithDelta(14900, (float) Commission::where('user_id', $upline->id)->where('type', 'join')->sum('amount'), 0.01);
    }

    public function test_stok_hq_kurang_rollback_total(): void
    {
        $admin = $this->user(User::ROLE_SUPER_ADMIN);
        $upline = $this->user(User::ROLE_DISTRIBUTOR);
        $p = $this->product(2);        // cuma 2
        $paket = $this->paket($p, 5);  // butuh 5 → gagal

        try {
            app(OnboardingService::class)->onboard($this->data(), $paket, $upline->id, $admin->id);
            $this->fail('harusnya lempar RuntimeException');
        } catch (RuntimeException $e) {
            // expected
        }

        // Rollback total: user tak dibuat, stok utuh, nol transaksi, nol komisi
        $this->assertSame(0, User::where('role', User::ROLE_RESELLER_BRONZE)->count());
        $this->assertSame(2, (int) $p->fresh()->hq_stock);
        $this->assertSame(0, JoinTransaction::count());
        $this->assertSame(0, Commission::count());
    }

    public function test_tanpa_upline_user_dibuat_tanpa_bonus(): void
    {
        $admin = $this->user(User::ROLE_SUPER_ADMIN);
        $p = $this->product(100);
        $paket = $this->paket($p, 1);

        $reseller = app(OnboardingService::class)->onboard($this->data(), $paket, null, $admin->id);

        $this->assertNotNull($reseller->id);
        $this->assertNull($reseller->upline_id);
        $this->assertSame(0, Commission::count()); // tak ada inviter → nol bonus
    }
}
