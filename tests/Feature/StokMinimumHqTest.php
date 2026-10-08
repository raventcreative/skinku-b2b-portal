<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\HqStockReportService;
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

    public function test_stok_minimum_diisi_langsung_dari_tabel_tersimpan_otomatis(): void
    {
        $p = $this->produk('Serum Glow', 'SG-01', 8, null);
        $admin = $this->user(User::ROLE_ADMIN);
        $url = route('products.min-stock', $p);

        // Tabel menampilkan kolom isian per produk.
        $this->actingAs($admin)->get(route('products.index'))->assertOk()->assertSee('Stok Min.')->assertSee($url)->assertSee('data-simpan-min', false);

        $this->actingAs($admin)->patchJson($url, ['hq_min_stock' => 20])->assertOk()
            ->assertExactJson(['hq_min_stock' => 20, 'stok' => 8, 'menipis' => true]);
        $this->assertSame(20, $p->refresh()->hq_min_stock);
        $this->assertDatabaseHas('audit_logs', ['action' => 'update_product_min_stock', 'target_type' => 'product', 'target_id' => $p->id]);

        // Kosong = tanpa pengingat.
        $this->actingAs($admin)->patchJson($url, ['hq_min_stock' => ''])->assertOk()->assertJson(['hq_min_stock' => null, 'menipis' => false]);
        $this->assertNull($p->refresh()->hq_min_stock);

        // Isian ngawur → 422 JSON (bukan redirect), angka lama tetap.
        $p->update(['hq_min_stock' => 5]);
        $this->actingAs($admin)->patchJson($url, ['hq_min_stock' => -3])->assertStatus(422)->assertJsonStructure(['message']);
        $this->actingAs($admin)->patchJson($url, ['hq_min_stock' => 'abc'])->assertStatus(422);
        $this->assertSame(5, $p->refresh()->hq_min_stock);

        // Tanpa izin Kelola Produk → ditolak.
        $this->actingAs($this->user(User::ROLE_GUDANG))->patchJson($url, ['hq_min_stock' => 99])->assertForbidden();
        $this->assertSame(5, $p->refresh()->hq_min_stock);
    }

    public function test_produk_master_dan_pemantauan_stok_menandai_serta_menyaring_yang_menipis(): void
    {
        $this->produk('Serum Glow', 'SG-01', 8, 20);                 // menipis
        $this->produk('Sabun Yuki', 'YK-01', 50, 20);                // aman
        $this->produk('Toner Tanpa Min', 'TN-01', 3, null);          // tanpa minimum → tak diingatkan
        $this->produk('Lotion Nonaktif', 'LN-01', 1, 10, 'inactive'); // nonaktif → tak masuk pengingat/saringan
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)->get(route('products.index'))->assertOk()->assertSee('data-tanda-menipis', false)->assertSee('value="20"', false);
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

    /** Gerakan stok HQ; $qty positif = keluar, negatif = masuk. */
    private function gerak(Product $p, int $qty, string $ref, int $hariLalu, string $tipe = StockMovement::TYPE_OUT): void
    {
        StockMovement::create(['product_id' => $p->id, 'user_id' => null, 'movement_type' => $tipe, 'quantity' => abs($qty),
            'before_qty' => 1000, 'after_qty' => 1000 - $qty, 'reference_type' => $ref, 'created_at' => now()->subDays($hariLalu)]);
    }

    public function test_saran_stok_minimum_dari_barang_keluar_30_hari_kali_14_hari(): void
    {
        $serum = $this->produk('Serum Glow', 'SG-01', 100, null);
        $sabun = $this->produk('Sabun Yuki', 'YK-01', 50, 5);
        $toner = $this->produk('Toner Fresh', 'TN-01', 40, null);
        $this->gerak($serum, 20, 'tiktok_order', 5);
        $this->gerak($serum, 15, 'purchase_order', 10);
        $this->gerak($serum, 10, 'shopee_order', 2);
        $this->gerak($serum, 30, 'opname', 1, StockMovement::TYPE_ADJUSTMENT); // koreksi stok, bukan penjualan
        $this->gerak($serum, -200, 'production', 3, StockMovement::TYPE_IN);   // barang masuk
        $this->gerak($serum, 999, 'tiktok_order', 40);                          // di luar 30 hari
        $this->gerak($sabun, 3, 'purchase_order', 7);

        $saran = app(HqStockReportService::class)->saranStokMinimum();
        $this->assertSame(['saran' => 21, 'rata' => 1.5], $saran[$serum->id]); // 45 ÷ 30 = 1,5/hari × 14 = 21
        $this->assertSame(['saran' => 2, 'rata' => 0.1], $saran[$sabun->id]);  // 3 ÷ 30 × 14 = 1,4 → dibulatkan ke atas
        $this->assertArrayNotHasKey($toner->id, $saran);                        // tak ada barang keluar → tanpa saran
    }

    public function test_saran_tampil_di_tabel_dan_bisa_diisi_sekaligus_hanya_yang_kosong(): void
    {
        $serum = $this->produk('Serum Glow', 'SG-01', 100, null); // kosong + bersaran
        $sabun = $this->produk('Sabun Yuki', 'YK-01', 50, 5);     // sudah diisi → tak ditimpa
        $toner = $this->produk('Toner Fresh', 'TN-01', 40, null); // kosong tapi tanpa saran → tetap kosong
        $this->gerak($serum, 45, 'tiktok_order', 5);
        $this->gerak($sabun, 3, 'purchase_order', 7);
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)->get(route('products.index'))->assertOk()
            ->assertSee('data-saran="21"', false)->assertSee('saran 21')->assertSee('data-saran="2"', false)
            ->assertSee('Isi Stok Min. dari saran (1)');

        $this->actingAs($admin)->post(route('products.min-stock.saran'))->assertRedirect()
            ->assertSessionHas('status', 'Stok Min. diisi dari saran untuk 1 produk.');
        $this->assertSame([21, 5, null], [$serum->refresh()->hq_min_stock, $sabun->refresh()->hq_min_stock, $toner->refresh()->hq_min_stock]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'update_product_min_stock', 'target_type' => 'product', 'target_id' => $serum->id]);

        // Sudah sesuai saran → tautan saran produk itu & tombol isi sekaligus hilang.
        $this->actingAs($admin)->get(route('products.index'))->assertOk()
            ->assertDontSee('data-saran="21"', false)->assertDontSee('Isi Stok Min. dari saran');
        $this->actingAs($this->user(User::ROLE_GUDANG))->post(route('products.min-stock.saran'))->assertForbidden();
    }
}
