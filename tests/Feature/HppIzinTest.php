<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\MaterialPurchase;
use App\Models\Product;
use App\Models\Production;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * HPP, harga beli & biaya produksi khusus izin view_hpp (default hanya super admin): admin & gudang tetap bisa
 * mencatat (produk, beli bahan, produksi) tapi tak melihat hasil hitungan HPP/biaya, dan server tak menerima atau
 * menimpa angka HPP dari mereka.
 */
class HppIzinTest extends TestCase
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

    private function produk(array $extra = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Serum Glow', 'sku' => 'SG-01', 'status' => 'active', 'hq_stock' => 10,
            'price_distributor' => 20000, 'price_reseller' => 25000, 'price_retail' => 35000, 'cogs' => 12345,
        ], $extra));
    }

    private function bahan(float $avg = 40000): Material
    {
        return Material::create(['name' => 'Gliserin', 'unit' => 'kg', 'stock' => 100, 'avg_cost' => $avg, 'status' => Material::STATUS_ACTIVE]);
    }

    public function test_produk_master_tanpa_izin_tak_lihat_hpp_dan_tak_bisa_mengubahnya(): void
    {
        $p = $this->produk();
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)->get(route('products.index'))->assertOk()
            ->assertSee('Serum Glow')->assertDontSee('HPP / COGS')->assertDontSee('12.345')->assertDontSee('12345');
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN))->get(route('products.index'))->assertOk()
            ->assertSee('HPP / COGS')->assertSee('12.345');

        $isian = ['name' => 'Serum Glow', 'sku' => 'SG-01', 'status' => 'active', 'hq_stock' => 10,
            'price_distributor' => 20000, 'price_reseller' => 25000, 'price_retail' => 35000];
        // Edit oleh admin: HPP yang dikirim pun diabaikan → HPP lama tetap.
        $this->actingAs($admin)->put(route('products.update', $p), $isian + ['cogs' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(12345.0, (float) $p->refresh()->cogs);
        // Produk baru dari form tanpa kolom HPP → HPP awal 0 (diisi produksi/stok masuk/super admin).
        $this->actingAs($admin)->post(route('products.store'), array_merge($isian, ['sku' => 'B1', 'name' => 'Sabun']))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(0.0, (float) Product::where('sku', 'B1')->value('cogs'));
    }

    public function test_bahan_baku_tanpa_izin_tak_lihat_hpp_dan_tak_bisa_mengubahnya(): void
    {
        $m = $this->bahan(43210);
        MaterialPurchase::create(['material_id' => $m->id, 'material_name' => 'Gliserin', 'quantity' => 5, 'unit_cost' => 45678,
            'subtotal' => 228390, 'cost_before' => 41000, 'cost_after' => 43210, 'supplier_name' => 'CV Kimia', 'purchased_at' => '2026-10-02']);
        $gud = $this->user(User::ROLE_GUDANG);

        $this->actingAs($gud)->get(route('materials.index'))->assertOk()
            ->assertSee('Gliserin')->assertSee('CV Kimia')
            ->assertDontSee('HPP Rata-rata')->assertDontSee('43.210')->assertDontSee('45.678')->assertDontSee('name="avg_cost"', false);
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN))->get(route('materials.index'))->assertSee('43.210')->assertSee('45.678');

        // Edit oleh gudang: HPP manual yang dikirim pun diabaikan.
        $this->actingAs($gud)->put(route('materials.update', $m), ['name' => 'Gliserin', 'unit' => 'kg', 'status' => 'active', 'avg_cost' => 1])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(43210.0, (float) $m->refresh()->avg_cost);

        // Izin bisa diberikan lewat Hak Akses → gudang ikut melihat HPP.
        RolePermission::create(['role' => User::ROLE_GUDANG, 'permission_key' => 'view_hpp', 'allowed' => true]);
        Permissions::flushCache();
        $this->actingAs($this->user(User::ROLE_GUDANG, 'gud2'))->get(route('materials.index'))->assertSee('43.210');
    }

    public function test_produksi_gudang_tetap_bisa_mencatat_tapi_biaya_tersembunyi_dan_harga_tak_bisa_diubah(): void
    {
        $p = $this->produk(['cogs' => 0, 'hq_stock' => 0]);
        $m = $this->bahan(40000);
        $gud = $this->user(User::ROLE_GUDANG);

        // Form input: HPP bahan tak ikut dikirim ke halaman, kolom harga & ringkasan biaya tak dirender.
        $this->actingAs($gud)->get(route('productions.create'))->assertOk()->assertDontSee('40000')
            ->assertSee('const LIHAT_HPP = false', false);
        // Harga ketikan (kalau dikirim) diabaikan → HPP rata-rata bahan.
        $this->actingAs($gud)->post('/productions', ['produced_at' => '2026-10-01', 'blocks' => [
            ['product_id' => $p->id, 'output_qty' => 10, 'materials' => [['material_id' => $m->id, 'quantity' => 2, 'unit_cost' => 99999]]],
        ]])->assertSessionHasNoErrors()->assertRedirect()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'tercatat') && ! str_contains($s, 'HPP/pcs')); // notifikasi tanpa angka HPP
        $prod = Production::with('materials')->sole();
        $this->assertSame(40000.0, (float) $prod->materials->first()->unit_cost);

        // Daftar & detail tanpa biaya/HPP (total 2 × 40.000 = 80.000; HPP/pcs 8.000); Riwayat HPP tertutup.
        $this->actingAs($gud)->get(route('productions.index'))->assertOk()->assertSee($prod->production_number)
            ->assertDontSee('HPP / Pcs')->assertDontSee('80.000');
        $this->actingAs($gud)->get(route('productions.show', $prod))->assertOk()->assertSee('Gliserin')
            ->assertDontSee('Biaya Bahan')->assertDontSee('80.000')->assertDontSee('8.000');
        $this->actingAs($gud)->get(route('products.hpp-history', $p))->assertForbidden();

        $sa = $this->user(User::ROLE_SUPER_ADMIN);
        $this->actingAs($sa)->get(route('productions.show', $prod))->assertSee('Biaya Bahan')->assertSee('80.000');
        $this->actingAs($sa)->get(route('products.hpp-history', $p))->assertOk();
    }

    public function test_edit_produksi_oleh_gudang_mempertahankan_harga_bahan_lama(): void
    {
        $p = $this->produk(['cogs' => 0, 'hq_stock' => 0]);
        $m = $this->bahan(40000);
        // Super admin mencatat dgn harga ketikan 50.000 (bukan rata-rata 40.000).
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN))->post('/productions', ['produced_at' => '2026-10-01', 'blocks' => [
            ['product_id' => $p->id, 'output_qty' => 10, 'materials' => [['material_id' => $m->id, 'quantity' => 2, 'unit_cost' => 50000]]],
        ]])->assertRedirect();
        $prod = Production::sole();
        $gud = $this->user(User::ROLE_GUDANG);

        $this->actingAs($gud)->get(route('productions.edit', $prod))->assertOk()->assertDontSee('50000');
        // Gudang mengubah qty dari form tanpa kolom harga → harga baris lama 50.000 dipertahankan (tak jadi 40.000).
        $this->actingAs($gud)->put(route('productions.update', $prod), [
            'produced_at' => '2026-10-01', 'output_qty' => 20,
            'materials' => [['material_id' => $m->id, 'quantity' => 4]], 'costs' => [],
        ])->assertRedirect(route('productions.show', $prod));

        $line = $prod->refresh()->materials()->sole();
        $this->assertSame([50000.0, 4.0], [(float) $line->unit_cost, (float) $line->quantity]);
        $this->assertSame(200000.0, (float) $prod->total_cost);
    }

    public function test_laporan_stok_hq_laporan_penjualan_dan_stok_masuk_tanpa_angka_hpp(): void
    {
        $this->produk();
        $admin = $this->user(User::ROLE_ADMIN);
        $sa = $this->user(User::ROLE_SUPER_ADMIN);

        $this->actingAs($admin)->get(route('hq-stock.report'))->assertOk()->assertDontSee('Nilai HPP')->assertSee('Nilai Jual');
        $this->actingAs($sa)->get(route('hq-stock.report'))->assertOk()->assertSee('Nilai HPP');
        $this->actingAs($admin)->get(route('reports.index'))->assertOk()->assertDontSee('Laba Kotor');
        $this->actingAs($sa)->get(route('reports.index'))->assertOk()->assertSee('Laba Kotor');
        $this->actingAs($admin)->get(route('stock-receipts.index'))->assertOk()->assertDontSee('Total Biaya');
        $this->actingAs($admin)->get(route('stock-receipts.create'))->assertOk()->assertDontSee('HPP Skrg')->assertDontSee('12345');
    }

    public function test_akuntansi_tanpa_izin_tetap_bisa_jurnal_tapi_laporan_keuangan_tertutup(): void
    {
        $admin = $this->user(User::ROLE_ADMIN); // punya Akuntansi (view_accounting), tanpa Lihat HPP

        foreach (['accounting.report', 'accounting.income-statement', 'accounting.balance-sheet', 'accounting.cash-flow',
            'accounting.comparison', 'accounting.trend', 'accounting.trial-balance'] as $laporan) {
            $this->actingAs($admin)->get(route($laporan))->assertForbidden();
        }
        $this->actingAs($admin)->get(route('accounting.index'))->assertRedirect(route('accounting.journals'));
        $this->actingAs($admin)->get(route('accounting.journals'))->assertOk()
            ->assertSee('Jurnal Umum')->assertDontSee(route('accounting.report'))->assertDontSee(route('accounting.trial-balance'));
        $this->actingAs($admin)->get(route('accounting.accounts'))->assertOk();
        $this->actingAs($admin)->get(route('accounting.excel-import'))->assertOk();
        // Generate Report: bagian Keuangan (Laba Rugi) ikut tertutup.
        $this->actingAs($admin)->get(route('reports.business', ['jenis' => 'semua']))->assertOk()->assertDontSee('Keuangan — Laba Rugi');

        $sa = $this->user(User::ROLE_SUPER_ADMIN);
        $this->actingAs($sa)->get(route('accounting.index'))->assertRedirect(route('accounting.report'));
        $this->actingAs($sa)->get(route('accounting.journals'))->assertOk()->assertSee(route('accounting.report'));
        $this->actingAs($sa)->get(route('reports.business', ['jenis' => 'semua']))->assertOk()->assertSee('Keuangan — Laba Rugi');

        // Izin bisa diberikan ke role lain lewat Hak Akses.
        RolePermission::create(['role' => User::ROLE_ADMIN, 'permission_key' => 'view_hpp', 'allowed' => true]);
        Permissions::flushCache();
        $this->actingAs($admin)->get(route('accounting.income-statement'))->assertOk();
    }
}
