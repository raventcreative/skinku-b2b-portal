<?php

namespace Tests\Feature;

use App\Models\AccAccount;
use App\Models\AccBranch;
use App\Models\PartnerSale;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\AccountingService;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\FinancialReportService;
use App\Services\ReportService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Alat Asisten AI menu Laporan & Keuangan — izin & cakupan = halamannya: Laporan Penjualan/Pembelian = view_reports
 * (mitra hanya PO miliknya, laba kotor khusus Lihat HPP); Omzet Mitra & Generate Report = view_reports + staf;
 * Penjualan Downline = mitra stockist (miliknya saja); laporan keuangan Akuntansi = view_accounting + Lihat HPP;
 * Penarikan = process_withdrawal (tanpa data rekening). Angka = service yang sama dgn halaman.
 */
class AiLaporanKeuanganToolsTest extends TestCase
{
    use RefreshDatabase;

    private const ALAT = ['laporan_penjualan', 'omzet_mitra', 'penjualan_downline', 'laporan_bisnis', 'laporan_keuangan', 'penarikan'];

    private function user(string $role, ?string $u = null): User
    {
        $u ??= $role;

        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    /** Nama alat Laporan & Keuangan yang tersedia untuk user ini. */
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

    private function produk(): Product
    {
        return Product::create(['name' => 'Serum Glow', 'sku' => 'SG-01', 'status' => 'active', 'hq_stock' => 100,
            'price_distributor' => 20000, 'price_reseller' => 25000, 'price_retail' => 35000, 'cogs' => 12000]);
    }

    /** PO bulan ini; $seller null = PO mitra ke HQ. */
    private function po(User $pembeli, Product $p, int $qty, int $harga, string $status = PurchaseOrder::STATUS_COMPLETED, ?User $seller = null): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-'.uniqid(), 'created_by' => $pembeli->id, 'user_id' => $pembeli->id, 'seller_id' => $seller?->id,
            'company_name' => $pembeli->fullname, 'user_role' => $pembeli->role, 'status' => $status,
            'total_amount' => $qty * $harga, 'order_date' => now()->toDateString(),
            'completed_at' => $status === PurchaseOrder::STATUS_COMPLETED ? now() : null,
        ]);
        PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'product_id' => $p->id, 'product_name' => $p->name,
            'sku' => $p->sku, 'qty' => $qty, 'unit_price' => $harga, 'total_price' => $qty * $harga]);

        return $po;
    }

    public function test_alat_tersedia_sesuai_izin_role(): void
    {
        $this->assertSame(['laporan_penjualan', 'omzet_mitra', 'laporan_bisnis', 'laporan_keuangan', 'penarikan'],
            $this->alat($this->user(User::ROLE_SUPER_ADMIN)));
        // Admin: punya Akuntansi tapi tanpa Lihat HPP → laporan keuangan tertutup (sama dgn halaman).
        $this->assertSame(['laporan_penjualan', 'omzet_mitra', 'laporan_bisnis', 'penarikan'], $this->alat($this->user(User::ROLE_ADMIN)));
        $this->assertSame(['laporan_penjualan', 'omzet_mitra', 'laporan_bisnis'], $this->alat($this->user(User::ROLE_GUDANG)));
        $this->assertSame(['laporan_penjualan', 'penjualan_downline'], $this->alat($this->user(User::ROLE_DISTRIBUTOR)));
        $this->assertSame([], $this->alat($this->user(User::ROLE_RESELLER))); // tak punya menu Laporan

        RolePermission::create(['role' => User::ROLE_ADMIN, 'permission_key' => 'view_hpp', 'allowed' => true]);
        Permissions::flushCache();
        $this->assertContains('laporan_keuangan', $this->alat($this->user(User::ROLE_ADMIN, 'admin2')));
    }

    public function test_laporan_penjualan_staf_lengkap_laba_kotor_khusus_hpp_mitra_hanya_miliknya(): void
    {
        $p = $this->produk();
        $alfa = $this->user(User::ROLE_DISTRIBUTOR, 'alfa');
        $budi = $this->user(User::ROLE_DISTRIBUTOR, 'budi');
        $this->po($alfa, $p, 10, 30000);                                   // 300.000
        $this->po($budi, $p, 5, 40000);                                    // 200.000
        $this->po($alfa, $p, 1, 30000, PurchaseOrder::STATUS_PENDING);     // pending: tak masuk penjualan

        $admin = $this->pakai('laporan_penjualan', $this->user(User::ROLE_ADMIN));
        $this->assertSame([500000.0, 3, 1, 2], [$admin['total_penjualan'], $admin['po_total'], $admin['po_pending'], $admin['po_selesai']]);
        $this->assertSame(['ALFA', 'BUDI'], array_column($admin['per_mitra'], 'mitra'));
        $this->assertArrayNotHasKey('laba_kotor', $admin);
        $this->assertArrayHasKey('catatan_akses', $admin);

        $sa = $this->pakai('laporan_penjualan', $this->user(User::ROLE_SUPER_ADMIN));
        $this->assertSame(['penjualan' => 500000.0, 'hpp' => 180000.0, 'laba_kotor' => 320000.0, 'margin_persen' => 64.0], $sa['laba_kotor']);

        // Mitra: Laporan Pembelian — hanya PO miliknya, tanpa rincian mitra lain.
        $mitra = $this->pakai('laporan_penjualan', $alfa);
        $this->assertSame([300000.0, 2], [$mitra['total_penjualan'], $mitra['po_total']]);
        $this->assertArrayNotHasKey('per_mitra', $mitra);
        $this->assertStringNotContainsString('BUDI', json_encode($mitra));
    }

    public function test_omzet_mitra_sama_dengan_halaman_dan_saringan_nama(): void
    {
        $p = $this->produk();
        $alfa = $this->user(User::ROLE_DISTRIBUTOR, 'alfa');
        $budi = $this->user(User::ROLE_DISTRIBUTOR, 'budi');
        $this->po($this->user(User::ROLE_RESELLER, 'citra'), $p, 5, 30000, PurchaseOrder::STATUS_COMPLETED, $alfa); // alfa jual ke downline 150.000
        PartnerSale::create(['sale_number' => 'NS-1', 'user_id' => $budi->id, 'customer_name' => 'Pembeli', 'total_amount' => 80000,
            'sold_at' => now()->toDateString(), 'created_by' => $budi->id]);                                      // budi jual ke customer 80.000
        $admin = $this->user(User::ROLE_ADMIN);

        $out = $this->pakai('omzet_mitra', $admin);
        $this->assertSame(230000.0, $out['total_omzet_semua_mitra']);
        $this->assertSame([['ALFA', 150000.0, 0.0, 150000.0], ['BUDI', 0.0, 80000.0, 80000.0]],
            array_map(fn ($r) => [$r['mitra'], $r['jual_downline'], $r['jual_customer'], $r['total']], $out['per_mitra']));
        $this->assertSame(array_column(app(ReportService::class)->omzetPerMitra(now()->startOfMonth()), 'total'), array_column($out['per_mitra'], 'total'));

        $this->assertSame(['BUDI'], array_column($this->pakai('omzet_mitra', $admin, ['cari' => 'bud'])['per_mitra'], 'mitra'));
        $kosong = $this->pakai('omzet_mitra', $admin, ['cari' => 'zzz']);
        $this->assertSame([], $kosong['per_mitra']);
        $this->assertStringContainsString('ulangi tanpa cari', $kosong['catatan']);
    }

    public function test_penjualan_downline_hanya_milik_mitra_itu(): void
    {
        $p = $this->produk();
        $alfa = $this->user(User::ROLE_DISTRIBUTOR, 'alfa');
        $dodi = $this->user(User::ROLE_DISTRIBUTOR, 'dodi');
        $this->po($this->user(User::ROLE_RESELLER, 'citra'), $p, 5, 30000, PurchaseOrder::STATUS_COMPLETED, $alfa);
        $this->po($this->user(User::ROLE_RESELLER, 'eka'), $p, 33, 30000, PurchaseOrder::STATUS_COMPLETED, $dodi); // milik distributor lain

        $out = $this->pakai('penjualan_downline', $alfa);
        $this->assertSame([150000.0, 1, 1], [$out['penjualan_bersih'], $out['po_masuk'], $out['po_selesai']]);
        $this->assertSame(['CITRA'], array_column($out['per_downline'], 'downline'));
        $this->assertSame([['produk' => 'Serum Glow', 'unit' => 5, 'rupiah' => 150000.0]], $out['per_produk']);
        $this->assertStringNotContainsString('EKA', json_encode($out));
    }

    public function test_laporan_bisnis_laba_rugi_ikut_izin_akuntansi_dan_hpp(): void
    {
        $admin = $this->pakai('laporan_bisnis', $this->user(User::ROLE_ADMIN), ['jenis' => 'bulanan']);
        $this->assertArrayHasKey('penjualan', $admin);
        $this->assertNull($admin['keuangan']);
        $this->assertStringContainsString('Laba rugi tidak ditampilkan', $admin['catatan_akses']);

        $sa = $this->pakai('laporan_bisnis', $this->user(User::ROLE_SUPER_ADMIN), ['jenis' => 'bulanan']);
        $this->assertNotNull($sa['keuangan']);
        $this->assertArrayNotHasKey('catatan_akses', $sa);
    }

    public function test_laporan_keuangan_angka_sama_dengan_halaman_akuntansi(): void
    {
        $cabang = AccBranch::create(['code' => 'HQ', 'name' => 'Pusat', 'is_active' => true]);
        $akun = [];
        foreach ([['1002', 'Bank', 'asset', 'cash', 'debit'], ['1202', 'Persediaan Barang Jadi', 'asset', 'inventory', 'debit'],
            ['3001', 'Modal Usaha', 'equity', null, 'credit'], ['4001', 'Penjualan', 'revenue', 'sales', 'credit'],
            ['5003', 'Beban HPP', 'expense', 'cogs', 'debit'], ['6001', 'Beban Iklan', 'expense', 'operating', 'debit']] as [$kode, $nama, $tipe, $sub, $nb]) {
            $akun[$kode] = AccAccount::create(['code' => $kode, 'name' => $nama, 'type' => $tipe, 'subtype' => $sub, 'normal_balance' => $nb])->id;
        }
        $jurnal = fn (string $tgl, string $debit, string $kredit, int $rp) => app(AccountingService::class)->record(
            ['branch_id' => $cabang->id, 'date' => $tgl],
            [['account_id' => $akun[$debit], 'debit' => $rp], ['account_id' => $akun[$kredit], 'credit' => $rp]],
        );
        $jurnal('2026-06-01', '1002', '3001', 200_000_000);
        $jurnal('2026-06-01', '1202', '3001', 50_000_000);
        $jurnal('2026-06-10', '1002', '4001', 100_000_000);
        $jurnal('2026-06-10', '5003', '1202', 40_000_000);
        $jurnal('2026-06-15', '6001', '1002', 20_000_000);
        $sa = $this->user(User::ROLE_SUPER_ADMIN);

        $out = $this->pakai('laporan_keuangan', $sa);
        $lr = $out['laba_rugi'];
        $this->assertSame('2026-06', $out['periode']); // kosong → bulan buku terakhir
        $this->assertSame([100000000.0, 40000000.0, 60000000.0, 60.0, 20000000.0, 40000000.0],
            [$lr['penjualan_bersih'], $lr['hpp'], $lr['laba_kotor'], $lr['margin_kotor_persen'], $lr['beban_operasional'], $lr['laba_bersih']]);
        $this->assertSame(app(FinancialReportService::class)->incomeStatement('2026-06')['net_income'], $lr['laba_bersih']);
        $this->assertTrue($out['neraca']['seimbang']);
        $this->assertSame('6001 · Beban Iklan', $lr['beban_operasional_terbesar'][0]['akun']);

        $this->assertStringContainsString('belum punya jurnal', $this->pakai('laporan_keuangan', $sa, ['periode' => '2025-01'])['catatan']);
        $this->assertNull(app(ToolRegistry::class)->find('laporan_keuangan', $this->user(User::ROLE_ADMIN))); // tanpa Lihat HPP
    }

    public function test_penarikan_rekap_per_status_tanpa_data_rekening(): void
    {
        foreach ([['alfa', 100000, 'diajukan'], ['budi', 50000, 'disetujui'], ['citra', 70000, 'cair'], ['dodi', 30000, 'ditolak']] as [$u, $rp, $status]) {
            Withdrawal::create(['user_id' => $this->user(User::ROLE_RESELLER, $u)->id, 'amount' => $rp, 'status' => $status,
                'bank' => 'BCA', 'no_rekening' => '9876543210', 'atas_nama' => 'Nama Rahasia', 'note' => 'catatan internal', 'requested_at' => now()]);
        }
        $admin = $this->user(User::ROLE_ADMIN);

        $out = $this->pakai('penarikan', $admin);
        $this->assertSame(['jumlah' => 1, 'total' => 100000.0], $out['per_status']['diajukan']);
        $this->assertSame(['jumlah' => 2, 'total' => 150000.0], $out['perlu_diproses']);
        $this->assertCount(4, $out['daftar_terbaru']);
        $json = json_encode($out);
        foreach (['9876543210', 'Nama Rahasia', 'BCA', 'catatan internal'] as $rahasia) {
            $this->assertStringNotContainsString($rahasia, $json);
        }
        $this->assertSame(['CITRA'], array_column($this->pakai('penarikan', $admin, ['status' => 'cair'])['daftar_terbaru'], 'mitra'));
        $this->assertNull(app(ToolRegistry::class)->find('penarikan', $this->user(User::ROLE_GUDANG)));
    }
}
