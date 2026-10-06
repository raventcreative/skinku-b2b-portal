<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Pengingat stok PUSAT menipis: stok minimum per produk (Produk Master) → banner Dashboard utk staf yang pegang
 * stok/produk, tanda "menipis" di Produk Master & Pemantauan Stok, saringan "stok pusat menipis", dan Asisten AI.
 */
class StokMinimumHqTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, ?string $u = null): User
    {
        $u ??= $role;

        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function produk(string $nama, string $sku, int $stok, ?int $min, string $status = 'active'): Product
    {
        return Product::create(['name' => $nama, 'sku' => $sku, 'status' => $status, 'hq_stock' => $stok, 'hq_min_stock' => $min,
            'price_distributor' => 20000, 'price_reseller' => 25000, 'price_retail' => 35000, 'cogs' => 10000]);
    }

    public function test_admin_atur_stok_minimum_pusat_di_produk_master(): void
    {
        $p = $this->produk('Serum Glow', 'SG-01', 50, null);
        $admin = $this->user(User::ROLE_ADMIN);
        $isian = ['name' => 'Serum Glow', 'sku' => 'SG-01', 'status' => 'active', 'hq_stock' => 50,
            'price_distributor' => 20000, 'price_reseller' => 25000, 'price_retail' => 35000];

        $this->actingAs($admin)->put(route('products.update', $p), $isian + ['hq_min_stock' => 20])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(20, $p->refresh()->hq_min_stock);

        $this->actingAs($admin)->put(route('products.update', $p), $isian + ['hq_min_stock' => ''])->assertSessionHasNoErrors();
        $this->assertNull($p->refresh()->hq_min_stock); // kosong = tanpa pengingat
    }

    public function test_produk_master_dan_pemantauan_stok_menandai_serta_menyaring_yang_menipis(): void
    {
        $this->produk('Serum Glow', 'SG-01', 8, 20);                 // menipis
        $this->produk('Sabun Yuki', 'YK-01', 50, 20);                // aman
        $this->produk('Toner Tanpa Min', 'TN-01', 3, null);          // tanpa minimum → tak diingatkan
        $this->produk('Lotion Nonaktif', 'LN-01', 1, 10, 'inactive'); // nonaktif → tak masuk pengingat/saringan
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)->get(route('products.index'))->assertOk()->assertSee('menipis · min 20')->assertSee('min 20');
        $this->actingAs($admin)->get(route('products.index', ['stok' => 'menipis']))->assertOk()
            ->assertSee('Serum Glow')->assertDontSee('Sabun Yuki')->assertDontSee('Toner Tanpa Min')->assertDontSee('Lotion Nonaktif');
        $this->actingAs($admin)->get(route('inventory.index'))->assertOk()->assertSee('menipis · min 20');
    }

    public function test_dashboard_banner_stok_pusat_menipis_utk_staf_stok_dan_produk(): void
    {
        $this->produk('Serum Glow', 'SG-01', 8, 20);
        $this->produk('Sabun Yuki', 'YK-01', 50, 20);

        $this->actingAs($this->user(User::ROLE_ADMIN))->get(route('dashboard'))->assertOk()
            ->assertSee('Stok pusat menipis')->assertSee('Serum Glow')->assertSee('/ min 20');
        $this->actingAs($this->user(User::ROLE_GUDANG))->get(route('dashboard'))->assertOk()->assertSee('Stok pusat menipis');
        // Mitra tak memegang stok pusat → tak ada banner.
        $this->actingAs($this->user(User::ROLE_DISTRIBUTOR))->get(route('dashboard'))->assertOk()->assertDontSee('Stok pusat menipis');
    }

    public function test_dashboard_tanpa_banner_bila_tak_ada_yang_menipis(): void
    {
        $this->produk('Sabun Yuki', 'YK-01', 50, 20);

        $this->actingAs($this->user(User::ROLE_ADMIN))->get(route('dashboard'))->assertOk()->assertDontSee('Stok pusat menipis');
    }

    public function test_peringatan_stok_rendah_mitra_abaikan_minimum_nol(): void
    {
        $p = $this->produk('Serum Glow', 'SG-01', 50, null);
        $dist = $this->user(User::ROLE_DISTRIBUTOR);
        Inventory::create(['user_id' => $dist->id, 'product_id' => $p->id, 'quantity' => 0, 'minimum_stock' => 0]);

        // Minimum belum diisi → bukan "stok rendah" (aturan halaman Pemantauan Stok).
        $this->actingAs($dist)->get(route('dashboard'))->assertOk()->assertSee('Semua stok dalam kondisi normal.');
    }

    public function test_asisten_ai_tahu_stok_pusat_menipis(): void
    {
        $this->produk('Serum Glow', 'SG-01', 8, 20);
        $this->produk('Sabun Yuki', 'YK-01', 50, 20);
        $admin = $this->user(User::ROLE_ADMIN);

        $out = app(ToolRegistry::class)->find('pemantauan_stok', $admin)->run([], $admin);
        $this->assertSame(1, $out['stok_pusat_menipis']);
        $this->assertSame([
            ['produk' => 'Sabun Yuki', 'sku' => 'YK-01', 'stok' => 50, 'minimum' => 20, 'menipis' => false],
            ['produk' => 'Serum Glow', 'sku' => 'SG-01', 'stok' => 8, 'minimum' => 20, 'menipis' => true],
        ], $out['stok_pusat']);

        $pm = collect(app(ToolRegistry::class)->find('produk_master', $admin)->run([], $admin)['produk'])->firstWhere('sku', 'SG-01');
        $this->assertSame([20, true], [$pm['stok_minimum'], $pm['stok_menipis']]);
    }
}
