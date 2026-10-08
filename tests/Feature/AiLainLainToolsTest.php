<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\LearningModule;
use App\Models\Lesson;
use App\Models\Material;
use App\Models\MaterialPurchase;
use App\Models\OkrCycle;
use App\Models\Product;
use App\Models\RoiItem;
use App\Models\RoiSetting;
use App\Models\RolePermission;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\RoiCalculatorService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Alat Asisten AI menu lain-lain — izin = halamannya: Supplier = manage_production, Kalkulator ROI =
 * manage_roi_calculator (modal/profit/target ROI khusus view_hpp), OKR = okr.view + internal (mitra diblok),
 * Academy = view_learning (materi sesuai audiens). Kontak supplier tak pernah dikirim ke AI.
 */
class AiLainLainToolsTest extends TestCase
{
    use RefreshDatabase;

    private const ALAT = ['supplier', 'kalkulator_roi', 'okr', 'academy'];

    private function user(string $role, ?string $u = null): User
    {
        $u ??= $role;

        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function izinkan(string $role, string $perm): void
    {
        RolePermission::create(['role' => $role, 'permission_key' => $perm, 'allowed' => true]);
        Permissions::flushCache();
    }

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

    public function test_alat_tersedia_sesuai_izin_role(): void
    {
        $this->assertSame(self::ALAT, $this->alat($this->user(User::ROLE_SUPER_ADMIN)));
        $this->assertSame(self::ALAT, $this->alat($this->user(User::ROLE_ADMIN)));
        $this->assertSame(['supplier', 'okr', 'academy'], $this->alat($this->user(User::ROLE_GUDANG)));
        $this->assertSame(['academy'], $this->alat($this->user(User::ROLE_DISTRIBUTOR)));

        // OKR khusus tim internal: izin tercentang untuk mitra pun tetap tertutup (= middleware internal).
        $this->izinkan(User::ROLE_DISTRIBUTOR, 'okr.view');
        $dist = $this->user(User::ROLE_DISTRIBUTOR, 'dist2');
        $this->assertSame(['academy'], $this->alat($dist));
        $this->assertNull(app(ToolRegistry::class)->find('okr', $dist));
    }

    public function test_supplier_tanpa_kontak_dan_nilai_beli_khusus_hpp(): void
    {
        $sari = Supplier::create(['name' => 'CV Sari Kimia', 'phone' => '0812-RAHASIA', 'address' => 'Jl. RAHASIA 1', 'notes' => 'CATATAN-RAHASIA', 'status' => 'active']);
        Supplier::create(['name' => 'PT Botol Jaya', 'status' => 'inactive']);
        $gliserin = Material::create(['name' => 'Gliserin', 'unit' => 'kg', 'stock' => 0, 'avg_cost' => 0, 'status' => 'active']);
        $beli = fn (string $tgl, int $sub) => MaterialPurchase::create(['material_id' => $gliserin->id, 'material_name' => 'Gliserin',
            'supplier_id' => $sari->id, 'supplier_name' => $sari->name, 'quantity' => 10, 'unit_cost' => $sub / 10, 'subtotal' => $sub, 'purchased_at' => $tgl]);
        $beli('2026-09-01', 500_000);
        $beli('2026-10-02', 650_000);

        // Admin (tanpa Lihat HPP): daftar & jumlah beli, tanpa rupiah.
        $out = $this->pakai('supplier', $this->user(User::ROLE_ADMIN));
        $this->assertSame([2, 1], [$out['jumlah_supplier'], $out['aktif']]);
        $this->assertSame(['nama' => 'CV Sari Kimia', 'status' => 'aktif', 'jumlah_pembelian' => 2, 'bahan' => ['Gliserin'],
            'pembelian_terakhir' => '2026-10-02'], $out['supplier'][0]);
        $this->assertSame(['nama' => 'PT Botol Jaya', 'status' => 'nonaktif', 'jumlah_pembelian' => 0], $out['supplier'][1]);
        $this->assertArrayHasKey('catatan_akses', $out);
        $this->assertStringNotContainsString('RAHASIA', json_encode($out));

        // Super admin: + total pembelian; cari nama sebagian.
        $sa = $this->pakai('supplier', $this->user(User::ROLE_SUPER_ADMIN), ['cari' => 'sari']);
        $this->assertSame([1, 1_150_000], [$sa['jumlah_supplier'], $sa['supplier'][0]['total_pembelian']]);
        $this->assertArrayNotHasKey('catatan_akses', $sa);
    }

    public function test_kalkulator_roi_modal_dan_target_khusus_hpp(): void
    {
        $serum = Product::create(['name' => 'Serum Glow', 'sku' => 'SG-1', 'hq_stock' => 0, 'status' => 'active',
            'price_distributor' => 20000, 'price_reseller' => 25000, 'price_retail' => 35000, 'cogs' => 15000]);
        $item = RoiItem::create(['product_id' => $serum->id, 'selling_price' => 100_000]);
        $super = $this->user(User::ROLE_SUPER_ADMIN);

        $out = $this->pakai('kalkulator_roi', $super);
        $hasil = app(RoiCalculatorService::class)->rowFor($item->load('product'), RoiSetting::current())['result'];
        $p = $out['produk'][0];
        $this->assertSame(['Serum Glow', 'SG-1', 100_000, 15_000], [$p['nama'], $p['sku'], $p['harga_jual'], $p['modal']]);
        $this->assertSame([(int) round($hasil['profit_bersih']), round($hasil['bep_roi'], 2), round($hasil['avg_min'], 2)],
            [$p['profit_bersih'], $p['bep_roi'], $p['target_min_10']]);
        $this->assertSame([5, 10, 15, 20], array_keys($p['target_roi_tanpa_affiliate']));
        $this->assertSame(round($hasil['avg_min'], 2), $out['rata_rata_semua_produk']['target_min']); // satu produk → rata-rata = dirinya
        // Sama dengan tabel halaman.
        $page = $this->actingAs($super)->get(route('roi-calculator.index'))->assertOk()->viewData('rows');
        $this->assertSame((int) round($page[0]['result']['profit_bersih']), $p['profit_bersih']);

        // Admin (tanpa Lihat HPP): hanya setelan & harga jual — modal/profit/target bisa membuka HPP.
        $admin = $this->pakai('kalkulator_roi', $this->user(User::ROLE_ADMIN));
        $this->assertSame(['nama' => 'Serum Glow', 'sku' => 'SG-1', 'harga_jual' => 100_000], $admin['produk'][0]);
        $this->assertArrayNotHasKey('rata_rata_semua_produk', $admin);
        $this->assertStringContainsString('Lihat HPP', $admin['catatan_akses']);
        $this->assertStringNotContainsString('15000', json_encode($admin));
    }

    public function test_okr_progres_sama_dengan_rumus_halaman(): void
    {
        $sa = $this->user(User::ROLE_SUPER_ADMIN);
        $budi = $this->user(User::ROLE_ADMIN, 'budi');
        $kolom = Board::create(['name' => 'Papan OKR', 'created_by' => $sa->id])->columns()->create(['name' => 'To Do', 'position' => 0]);
        $cycle = OkrCycle::create(['name' => 'OKR Oktober 2026', 'period_type' => OkrCycle::PERIOD_MONTHLY, 'period_label' => 'Oktober 2026',
            'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'scope_type' => OkrCycle::SCOPE_COMPANY, 'direction' => 'Naikkan penjualan TikTok.',
            'status' => OkrCycle::STATUS_ACTIVE, 'created_by' => $sa->id]);
        OkrCycle::create(['name' => 'OKR Q4 Draft', 'period_type' => OkrCycle::PERIOD_QUARTERLY, 'period_label' => 'Q4 2026',
            'start_date' => '2026-10-01', 'end_date' => '2026-12-31', 'scope_type' => OkrCycle::SCOPE_COMPANY, 'direction' => 'Draf.', 'status' => OkrCycle::STATUS_DRAFT, 'created_by' => $sa->id]);
        $obj = $cycle->objectives()->create(['specialist' => 'cmo', 'title' => 'Dominasi TikTok Shop', 'owner_name' => 'Budi', 'position' => 0]);
        $kr = $obj->keyResults()->create(['title' => 'GMV TikTok 500 juta', 'metric' => 'GMV', 'baseline' => '300 juta', 'target' => '500 juta',
            'owner_user_id' => $budi->id, 'due_date' => '2026-10-31', 'position' => 0]);
        foreach (['Live harian' => now(), 'Brief 10 KOL' => null, 'Voucher payday' => null] as $judul => $selesai) {
            $card = $kolom->cards()->create(['title' => $judul, 'position' => 0, 'created_by' => $sa->id]);
            $card->forceFill(['completed_at' => $selesai])->save(); // completed_at tak fillable (diisi saat kartu dipindah ke Selesai)
            $kr->tasks()->create(['title' => $judul, 'assignee_name' => 'Budi', 'board_column_id' => $kolom->id, 'board_card_id' => $card->id, 'position' => 0]);
        }
        $gudang = $this->user(User::ROLE_GUDANG);

        $out = $this->pakai('okr', $gudang);
        $this->assertSame(2, $out['jumlah_siklus']);
        $okt = collect($out['siklus'])->firstWhere('nama', 'OKR Oktober 2026');
        $this->assertSame(['tugas_selesai' => 1, 'total_tugas' => 3, 'persen' => 33], $okt['progres']);
        $this->assertSame(['aktif', ['CMO: Dominasi TikTok Shop']], [$okt['status'], $okt['objective']]);

        $detail = $this->pakai('okr', $gudang, ['nama' => 'oktober']);
        $this->assertSame('Naikkan penjualan TikTok.', $detail['arah']);
        $k = $detail['objective'][0]['key_result'][0];
        $this->assertSame(['GMV TikTok 500 juta', 'GMV', '300 juta', '500 juta', '2026-10-31', 'BUDI'],
            [$k['judul'], $k['metrik'], $k['baseline'], $k['target'], $k['tenggat'], $k['pic']]);
        $this->assertSame([true, false, false], array_column($k['tugas'], 'selesai'));
        $this->assertArrayHasKey('error', $this->pakai('okr', $gudang, ['nama' => 'okr'])); // dua siklus cocok → tanya balik
    }

    public function test_academy_materi_sesuai_audiens(): void
    {
        $modul = LearningModule::create(['title' => 'Dasar Produk', 'description' => 'Kenali produk SKINKU', 'sort_order' => 1, 'is_published' => true]);
        LearningModule::create(['title' => 'Modul Draft', 'sort_order' => 2, 'is_published' => false]);
        Lesson::create(['module_id' => $modul->id, 'type' => 'video', 'title' => 'Cara pakai serum', 'category' => 'Produk',
            'description' => 'Langkah pemakaian serum glow', 'video_url' => 'https://youtu.be/x', 'is_published' => true]);
        Lesson::create(['type' => 'video', 'title' => 'Strategi distributor', 'category' => 'Bisnis', 'video_url' => 'https://youtu.be/y',
            'audience' => [User::ROLE_DISTRIBUTOR], 'is_published' => true]);
        Lesson::create(['type' => 'video', 'title' => 'Materi belum terbit', 'video_url' => 'https://youtu.be/z', 'is_published' => false]);
        $reseller = $this->user(User::ROLE_RESELLER);

        $out = $this->pakai('academy', $reseller);
        $this->assertSame([1, 1], [$out['jumlah_modul'], $out['jumlah_materi']]);
        $this->assertSame([['judul' => 'Dasar Produk', 'deskripsi' => 'Kenali produk SKINKU', 'materi' => ['Cara pakai serum (Video, Produk)']]], $out['modul']);
        $this->assertArrayNotHasKey('materi_tanpa_modul', $out); // materi khusus distributor & draft tak terlihat
        $page = $this->actingAs($reseller)->get(route('learning.index'))->assertOk();
        $this->assertSame([$page->viewData('modules')->count(), $page->viewData('lessons')->count()], [$out['jumlah_modul'], $out['jumlah_materi']]);

        $dist = $this->pakai('academy', $this->user(User::ROLE_DISTRIBUTOR));
        $this->assertSame(['Strategi distributor (Video, Bisnis)'], $dist['materi_tanpa_modul']);

        $cari = $this->pakai('academy', $reseller, ['cari' => 'serum']);
        $this->assertSame(['judul' => 'Cara pakai serum', 'modul' => 'Dasar Produk', 'tipe' => 'Video', 'kategori' => 'Produk',
            'deskripsi' => 'Langkah pemakaian serum glow'], array_diff_key($cari['materi'][0], ['link' => 1]));
        $this->assertStringContainsString('/learning/', $cari['materi'][0]['link']);
        $this->assertArrayHasKey('catatan', $this->pakai('academy', $reseller, ['cari' => 'tidak ada']));
    }
}
