<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\JoinPackage;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OnboardingFlowTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        static $n = 0;
        $n++;

        return User::create(['name' => "u$n", 'fullname' => "U$n", 'username' => "u$n", 'email' => "u$n@t.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE]);
    }

    private function bronzePaket(): JoinPackage
    {
        $p = Product::create(['name' => 'Sabun', 'sku' => 'SB-1', 'price_distributor' => 24000,
            'price_reseller' => 29000, 'price_retail' => 39000, 'cogs' => 10000, 'hq_stock' => 100,
            'status' => Product::STATUS_ACTIVE]);
        $paket = JoinPackage::create(['name' => 'Bronze', 'target_role' => User::ROLE_RESELLER_BRONZE,
            'price' => 149000, 'is_active' => true]);
        $paket->items()->create(['product_id' => $p->id, 'qty' => 2]);

        return $paket;
    }

    public function test_admin_onboard_reseller_baru(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $upline = $this->user(User::ROLE_DISTRIBUTOR);
        $paket = $this->bronzePaket();

        $this->actingAs($admin)->post(route('onboarding.store'), [
            'fullname' => 'Budi Reseller', 'email' => 'budi@t.test', 'username' => 'budi',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'join_package_id' => $paket->id, 'upline_id' => $upline->id, 'paid' => 1,
        ])->assertRedirect();

        $reseller = User::where('username', 'budi')->first();
        $this->assertNotNull($reseller);
        $this->assertSame(User::ROLE_RESELLER_BRONZE, $reseller->role);
        $this->assertSame($upline->id, $reseller->upline_id);
        // Bonus join ke upline
        $this->assertEqualsWithDelta(14900, (float) Commission::where('user_id', $upline->id)->where('type', 'join')->sum('amount'), 0.01);
    }

    public function test_mitra_tak_bisa_onboard(): void
    {
        $mitra = $this->user(User::ROLE_DISTRIBUTOR);
        $this->actingAs($mitra)->get(route('onboarding.create'))->assertForbidden();
    }

    /**
     * Binding constraint: stock-insufficient RuntimeException from onboard() must be
     * caught and redirected with a flash error, never allowed to bubble into a 500.
     */
    public function test_stok_kurang_redirect_dengan_error_bukan_500(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $p = Product::create(['name' => 'Sabun Langka', 'sku' => 'SB-2', 'price_distributor' => 24000,
            'price_reseller' => 29000, 'price_retail' => 39000, 'cogs' => 10000, 'hq_stock' => 1,
            'status' => Product::STATUS_ACTIVE]);
        $paket = JoinPackage::create(['name' => 'Gold Langka', 'target_role' => User::ROLE_RESELLER_GOLD,
            'price' => 459000, 'is_active' => true]);
        $paket->items()->create(['product_id' => $p->id, 'qty' => 5]); // butuh 5, stok cuma 1

        $response = $this->actingAs($admin)->post(route('onboarding.store'), [
            'fullname' => 'Calon Gagal', 'email' => 'gagal@t.test', 'username' => 'gagal',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'join_package_id' => $paket->id, 'paid' => 1,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertNull(User::where('username', 'gagal')->first());
        $this->assertSame(1, (int) $p->fresh()->hq_stock);
    }

    /**
     * Render smoke test: guards against Blade compile-500 (recurring gotcha in this
     * codebase — see feedback-blade-json-array-literal). Not covered by the store-path
     * test above since that never actually renders the create form.
     */
    public function test_admin_bisa_buka_form_onboarding(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->user(User::ROLE_DISTRIBUTOR); // calon upline, harus muncul di dropdown
        $paket = $this->bronzePaket();

        $this->actingAs($admin)->get(route('onboarding.create'))
            ->assertOk()
            ->assertSee($paket->name)
            ->assertSee('Konfirmasi');
    }
}
