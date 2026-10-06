<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Material;
use App\Models\MaterialPurchase;
use App\Models\PoReturn;
use App\Models\PoReturnItem;
use App\Models\Product;
use App\Models\Production;
use App\Models\ProductionCost;
use App\Models\ProductionMaterial;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Ai\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Alat Asisten AI menu Produk & Operasional — izin & cakupan = halamannya: Produk Master = manage_products;
 * Pemantauan Stok = staf & mitra (mitra hanya stok sendiri); Retur = process_return, atau mitra atas PO sendiri;
 * Bahan Baku & Produksi = manage_production; Stok Opname = manage_hq_stock.
 */
class AiOperasionalToolsTest extends TestCase
{
    use RefreshDatabase;

    private const ALAT = ['produk_master', 'pemantauan_stok', 'retur', 'bahan_baku', 'produksi_hpp', 'stok_opname'];

    private function user(string $role, ?string $u = null, array $extra = []): User
    {
        $u ??= $role;

        return User::create(array_merge([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ], $extra));
    }

    /** Nama alat operasional yang tersedia untuk user ini. */
    private function alat(User $user): array
    {
        $names = array_map(fn ($t) => $t->name(), app(ToolRegistry::class)->forUser($user));

        return array_values(array_intersect(self::ALAT, $names));
    }

    private function pakai(string $alat, User $user, array $args = []): array
    {
        $tool = app(ToolRegistry::class)->find($alat, $user);
        $this->assertNotNull($tool, "alat {$alat} harus tersedia untuk {$user->role}");

        return $tool->run($args, $user);
    }

    private function produk(string $nama, string $sku, int $hq = 0, array $extra = []): Product
    {
        return Product::create(array_merge([
            'name' => $nama, 'sku' => $sku, 'hq_stock' => $hq, 'status' => 'active',
            'price_distributor' => 20000, 'price_reseller' => 25000, 'price_retail' => 35000, 'cogs' => 12000,
        ], $extra));
    }

    private function retur(User $mitra, string $no, Product $p, int $qty, string $status, float $kredit = 0): PoReturn
    {
        $po = PurchaseOrder::create([
            'po_number' => $no, 'created_by' => $mitra->id, 'user_id' => $mitra->id, 'company_name' => $mitra->fullname,
            'user_role' => $mitra->role, 'status' => PurchaseOrder::STATUS_PENDING, 'total_amount' => 200000,
            'order_date' => now()->toDateString(),
        ]);
        $item = PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'product_id' => $p->id, 'product_name' => $p->name,
            'sku' => $p->sku, 'qty' => 10, 'unit_price' => 20000, 'total_price' => 200000]);
        $r = PoReturn::create(['purchase_order_id' => $po->id, 'status' => $status, 'kondisi' => 'rusak', 'reason' => 'Kemasan penyok',
            'credit_amount' => $kredit]);
        PoReturnItem::create(['po_return_id' => $r->id, 'purchase_order_item_id' => $item->id, 'qty' => $qty]);

        return $r;
    }

    public function test_alat_operasional_mengikuti_hak_akses_role(): void
    {
        $this->assertSame(self::ALAT, $this->alat($this->user(User::ROLE_SUPER_ADMIN)));
        $this->assertSame(self::ALAT, $this->alat($this->user(User::ROLE_ADMIN)));
        // Gudang: tanpa manage_products (Produk Master).
        $this->assertSame(['pemantauan_stok', 'retur', 'bahan_baku', 'produksi_hpp', 'stok_opname'], $this->alat($this->user(User::ROLE_GUDANG)));
        // Mitra: hanya stok & retur miliknya.
        $this->assertSame(['pemantauan_stok', 'retur'], $this->alat($this->user(User::ROLE_DISTRIBUTOR)));
        // Bukan staf/mitra & tanpa izin menu → tak satu pun.
        $kol = $this->user('kol_specialist');
        $this->assertSame([], $this->alat($kol));
        $this->assertNull(app(ToolRegistry::class)->find('bahan_baku', $kol));
    }

    public function test_produk_master_cari_status_dan_harga_per_tier(): void
    {
        $this->produk('Serum Glow', 'SG-01', 120, ['category' => 'Serum', 'price_grand' => 18000, 'weight_grams' => 100]);
        $this->produk('Sabun Yuki', 'YK-01', 40, ['category' => 'Sabun', 'status' => 'inactive']);
        $this->produk('Toner Lama', 'TN-01', 5)->delete(); // terhapus (soft delete) → tak ikut, sama dgn halaman
        $admin = $this->user(User::ROLE_ADMIN);

        $out = $this->pakai('produk_master', $admin);

        $this->assertSame(2, $out['ringkasan']['jumlah_produk']);
        $this->assertEquals(['active' => 1, 'inactive' => 1], $out['ringkasan']['per_status']);
        $this->assertSame(160, $out['ringkasan']['total_stok_pusat']);
        $glow = collect($out['produk'])->firstWhere('sku', 'SG-01');
        $this->assertSame(['grand' => 18000.0, 'distributor' => 20000.0, 'reseller' => 25000.0, 'retail' => 35000.0], $glow['harga']);
        $this->assertSame([12000.0, 100, 120, 'Serum'], [$glow['hpp'], $glow['berat_gram'], $glow['stok_pusat'], $glow['kategori']]);
        $this->assertNull(collect($out['produk'])->firstWhere('sku', 'YK-01')['harga']['grand']);
        $this->assertSame(['Serum Glow'], array_column($this->pakai('produk_master', $admin, ['cari' => 'serum'])['produk'], 'nama'));
        $this->assertSame(['Sabun Yuki'], array_column($this->pakai('produk_master', $admin, ['status' => 'inactive'])['produk'], 'nama'));
    }

    public function test_pemantauan_stok_mitra_hanya_miliknya_staf_semua_tanpa_kontak(): void
    {
        $glow = $this->produk('Serum Glow', 'SG-01', 120);
        $yuki = $this->produk('Sabun Yuki', 'YK-01', 40);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, 'dist1', ['company_name' => 'CV Maju', 'phone' => '081277778888']);
        $lain = $this->user(User::ROLE_DISTRIBUTOR, 'dist2', ['company_name' => 'PT Lain']);
        Inventory::create(['user_id' => $dist->id, 'product_id' => $glow->id, 'quantity' => 8, 'minimum_stock' => 10]); // menipis
        Inventory::create(['user_id' => $dist->id, 'product_id' => $yuki->id, 'quantity' => 0, 'minimum_stock' => 0]);  // qty 0 → mitra tak lihat
        Inventory::create(['user_id' => $lain->id, 'product_id' => $glow->id, 'quantity' => 50, 'minimum_stock' => 0]); // min 0 → bukan menipis

        // Mitra: hanya stok miliknya yang qty > 0, tanpa stok pusat / stok mitra lain.
        $out = $this->pakai('pemantauan_stok', $dist);
        $this->assertSame('stok milikmu sendiri', $out['cakupan']);
        $this->assertSame([['produk' => 'Serum Glow', 'sku' => 'SG-01', 'qty' => 8, 'minimum' => 10, 'menipis' => true]], $out['stok']);
        $this->assertArrayNotHasKey('stok_pusat', $out);
        $this->assertArrayNotHasKey('per_produk', $out);

        // Staf: semua mitra (termasuk qty 0) + total per produk + stok pusat; kontak mitra tak pernah dikirim.
        $out = $this->pakai('pemantauan_stok', $this->user(User::ROLE_ADMIN));
        $this->assertSame(['jumlah_baris' => 3, 'total_qty' => 58, 'menipis' => 1], $out['ringkasan']);
        $this->assertSame(['produk' => 'Serum Glow', 'total_qty' => 58, 'jumlah_mitra' => 2], $out['per_produk'][0]);
        $this->assertEqualsCanonicalizing(['CV Maju', 'CV Maju', 'PT Lain'], array_column($out['stok'], 'mitra'));
        $this->assertSame(['Sabun Yuki', 'Serum Glow'], array_column($out['stok_pusat'], 'produk'));
        $this->assertStringNotContainsString('081277778888', json_encode($out));

        // Saring: hanya menipis, dan per nama mitra.
        $admin2 = $this->user(User::ROLE_ADMIN, 'adm2');
        $this->assertSame(['CV Maju'], array_column($this->pakai('pemantauan_stok', $admin2, ['hanya_menipis' => true])['stok'], 'mitra'));
        $this->assertSame(['PT Lain'], array_column($this->pakai('pemantauan_stok', $admin2, ['mitra' => 'lain'])['stok'], 'mitra'));
    }

    public function test_retur_mitra_hanya_atas_po_miliknya_staf_semua(): void
    {
        $p = $this->produk('Serum Glow', 'SG-01', 120);
        $dist = $this->user(User::ROLE_DISTRIBUTOR, 'dist1');
        $lain = $this->user(User::ROLE_DISTRIBUTOR, 'dist2');
        $this->retur($dist, 'PO-001', $p, 5, 'applied', 100000);
        $this->retur($lain, 'PO-002', $p, 3, 'pending');

        $out = $this->pakai('retur', $dist);
        $this->assertSame('retur atas PO milikmu', $out['cakupan']);
        $this->assertSame(['PO-001'], array_column($out['retur'], 'po'));
        $this->assertSame([['produk' => 'Serum Glow', 'qty' => 5]], $out['retur'][0]['barang']);
        $this->assertSame(['rusak', 'Kemasan penyok', 'applied', 100000.0], [$out['retur'][0]['kondisi'], $out['retur'][0]['alasan'],
            $out['retur'][0]['status'], $out['retur'][0]['nilai_kredit']]);

        $admin = $this->user(User::ROLE_ADMIN);
        $out = $this->pakai('retur', $admin);
        $this->assertSame(2, $out['ringkasan']['jumlah']);
        $this->assertEquals(['applied' => 1, 'pending' => 1], $out['ringkasan']['per_status']);
        $this->assertSame(100000.0, $out['ringkasan']['total_kredit_disetujui']);
        $pending = $this->pakai('retur', $admin, ['status' => 'pending'])['retur'];
        $this->assertSame(['PO-002'], array_column($pending, 'po'));
        $this->assertArrayNotHasKey('nilai_kredit', $pending[0]); // belum disetujui → belum ada kredit
    }

    public function test_bahan_baku_nilai_stok_dan_riwayat_beli(): void
    {
        $gliserin = Material::create(['name' => 'Gliserin', 'unit' => 'kg', 'stock' => 12.5, 'avg_cost' => 40000, 'status' => Material::STATUS_ACTIVE]);
        Material::create(['name' => 'Pewangi', 'unit' => 'liter', 'stock' => -2, 'avg_cost' => 100000, 'status' => Material::STATUS_ACTIVE]);
        MaterialPurchase::create(['material_id' => $gliserin->id, 'material_name' => 'Gliserin', 'quantity' => 10, 'unit_cost' => 42000,
            'subtotal' => 420000, 'cost_before' => 38000, 'cost_after' => 40000, 'supplier_name' => 'CV Kimia', 'purchased_at' => '2026-10-02']);

        $out = $this->pakai('bahan_baku', $this->user(User::ROLE_GUDANG));

        // Nilai stok = stok × HPP rata-rata (Pewangi minus ikut mengurangi, seperti di halaman).
        $this->assertSame(['jumlah_bahan' => 2, 'nilai_stok_total' => 300000.0, 'stok_minus' => 1], $out['ringkasan']);
        $this->assertSame(['nama' => 'Gliserin', 'satuan' => 'kg', 'stok' => 12.5, 'hpp_rata_rata' => 40000.0, 'nilai_stok' => 500000.0,
            'status' => 'active'], $out['bahan'][0]);
        $this->assertSame(['tanggal' => '2026-10-02', 'bahan' => 'Gliserin', 'qty' => 10.0, 'harga_unit' => 42000.0, 'subtotal' => 420000.0,
            'hpp_sebelum' => 38000.0, 'hpp_sesudah' => 40000.0, 'supplier' => 'CV Kimia'], $out['riwayat_beli'][0]);
    }

    public function test_produksi_hpp_ringkasan_rentang_dan_rincian_batch(): void
    {
        $p = $this->produk('Serum Glow', 'SG-01', 120);
        $gud = $this->user(User::ROLE_GUDANG);
        $b1 = Production::create(['production_number' => 'PRD-00001', 'product_id' => $p->id, 'product_name' => $p->name,
            'produced_at' => '2026-10-01', 'output_qty' => 100, 'material_cost' => 800000, 'other_cost' => 200000, 'total_cost' => 1000000,
            'hpp_per_unit' => 10000, 'cogs_before' => 9000, 'cogs_after' => 9500, 'notes' => 'Batch pertama', 'created_by' => $gud->id]);
        $gliserin = Material::create(['name' => 'Gliserin', 'unit' => 'kg', 'stock' => 0, 'avg_cost' => 40000, 'status' => Material::STATUS_ACTIVE]);
        ProductionMaterial::create(['production_id' => $b1->id, 'material_id' => $gliserin->id, 'material_name' => 'Gliserin', 'unit' => 'kg',
            'quantity' => 20, 'unit_cost' => 40000, 'subtotal' => 800000]);
        ProductionCost::create(['production_id' => $b1->id, 'label' => 'Kemasan', 'amount' => 200000]);
        Production::create(['production_number' => 'PRD-00002', 'product_id' => $p->id, 'product_name' => $p->name,
            'produced_at' => '2026-10-04', 'output_qty' => 50, 'material_cost' => 450000, 'other_cost' => 150000, 'total_cost' => 600000,
            'hpp_per_unit' => 12000, 'cogs_before' => 9500, 'cogs_after' => 10333.33, 'created_by' => $gud->id]);

        $out = $this->pakai('produksi_hpp', $gud);
        $this->assertSame(['PRD-00002', 'PRD-00001'], array_column($out['produksi'], 'nomor'));
        $this->assertSame(['produk' => 'Serum Glow', 'batch' => 2, 'qty' => 150, 'total_biaya' => 1600000.0, 'hpp_rata_rata_batch' => 10666.67],
            $out['ringkasan']['per_produk'][0]);
        $this->assertSame([10000.0, 9500.0, 'GUDANG'], [$out['produksi'][1]['hpp_per_pcs'], $out['produksi'][1]['hpp_rata_rata_sesudah'], $out['produksi'][1]['oleh']]);

        // Rentang tanggal (batas akhir ikut).
        $this->assertSame(['PRD-00001'], array_column($this->pakai('produksi_hpp', $gud, ['sampai' => '2026-10-01'])['produksi'], 'nomor'));

        // Rincian satu batch = halaman detail produksi.
        $r = $this->pakai('produksi_hpp', $gud, ['nomor' => 'PRD-00001']);
        $this->assertSame([['nama' => 'Gliserin', 'qty' => 20.0, 'satuan' => 'kg', 'harga_unit' => 40000.0, 'subtotal' => 800000.0]], $r['bahan']);
        $this->assertSame([['keterangan' => 'Kemasan', 'nominal' => 200000.0]], $r['biaya_lain_rinci']);
        $this->assertSame([800000.0, 200000.0, 9000.0, 9500.0], [$r['biaya_bahan'], $r['biaya_lain'], $r['hpp_rata_rata_sebelum'], $r['hpp_rata_rata_sesudah']]);
        $this->assertArrayHasKey('error', $this->pakai('produksi_hpp', $gud, ['nomor' => 'PRD-99999']));
    }

    public function test_stok_opname_dikelompokkan_per_tanggal_opname(): void
    {
        $glow = $this->produk('Serum Glow', 'SG-01', 95);
        $yuki = $this->produk('Sabun Yuki', 'YK-01', 42);
        $mutasi = fn (Product $p, int $sebelum, int $sesudah, string $at, string $ref = 'opname') => StockMovement::create([
            'product_id' => $p->id, 'user_id' => null, 'movement_type' => StockMovement::TYPE_ADJUSTMENT, 'quantity' => $sesudah - $sebelum,
            'before_qty' => $sebelum, 'after_qty' => $sesudah, 'reference_type' => $ref, 'notes' => 'Stok opname', 'created_at' => $at]);
        // Opname tgl 1 Okt dicatat 30 Sep 23:59:59 (saldo awal hari itu): Serum −5, Sabun +2. Opname 1 Sep: Serum +3.
        $mutasi($glow, 100, 95, '2026-09-30 23:59:59');
        $mutasi($yuki, 40, 42, '2026-09-30 23:59:59');
        $mutasi($glow, 97, 100, '2026-08-31 23:59:59');
        $mutasi($glow, 95, 90, '2026-10-03 10:00:00', 'manual'); // penyesuaian lain, bukan opname → tak ikut

        $out = $this->pakai('stok_opname', $this->user(User::ROLE_GUDANG));

        $this->assertSame(2, $out['jumlah_opname_tercatat']);
        $this->assertSame('2026-10-01', $out['opname_terakhir']);
        $okt = $out['opname'][0];
        $this->assertSame(['2026-10-01', 2, -3, 1, 1], [$okt['tanggal'], $okt['produk_disesuaikan'], $okt['total_selisih'],
            $okt['produk_lebih'], $okt['produk_kurang']]);
        $this->assertSame(['produk' => 'Serum Glow', 'sku' => 'SG-01', 'stok_sistem' => 100, 'hitungan_fisik' => 95, 'selisih' => -5], $okt['rincian'][0]);
        $this->assertSame('2026-09-01', $out['opname'][1]['tanggal']);
    }
}
