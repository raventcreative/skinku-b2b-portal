<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\JoinPackage;
use App\Models\JoinTransaction;
use App\Models\MemberDormancyRule;
use App\Models\PartnerSale;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Alat Asisten AI menu Mitra & Jaringan — izin & cakupan = halamannya: Struktur Jaringan = manage_users, Dormansi
 * Member = manage_member_dormancy, Paket Join = manage_join_packages (staf); Jaringan Saya / Rekrutan Saya = mitra
 * yang punya downline / rekrutan (hanya miliknya); Pesanan Downline = process_downline_po (hanya PO di mana dia
 * penjual). Tanpa kontak (telepon/email/alamat).
 */
class AiMitraJaringanToolsTest extends TestCase
{
    use RefreshDatabase;

    private const ALAT = ['struktur_jaringan', 'jaringan_saya', 'rekrutan_saya', 'dormansi_member', 'paket_join', 'pesanan_downline'];

    private function user(string $role, string $u, array $extra = []): User
    {
        return User::create(array_merge([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test", 'phone' => '0812-RAHASIA-'.$u,
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ], $extra));
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

    /** GRANDI → ALFA (distributor) → CITRA, DEWI (+ EKO nonaktif); BUDI distributor belum ditempatkan. */
    private function jaringan(): array
    {
        $gd = $this->user(User::ROLE_GRAND_DISTRIBUTOR, 'grandi', ['region' => 'Jakarta']);
        $alfa = $this->user(User::ROLE_DISTRIBUTOR, 'alfa', ['upline_id' => $gd->id, 'sponsor_id' => $gd->id, 'region' => 'Bali']);
        $citra = $this->user(User::ROLE_RESELLER_BRONZE, 'citra', ['upline_id' => $alfa->id, 'sponsor_id' => $alfa->id, 'region' => 'Bali']);
        $dewi = $this->user(User::ROLE_RESELLER_BRONZE, 'dewi', ['upline_id' => $alfa->id, 'sponsor_id' => $alfa->id]);
        $this->user(User::ROLE_RESELLER_GOLD, 'eko', ['upline_id' => $alfa->id, 'status' => User::STATUS_INACTIVE]);
        $budi = $this->user(User::ROLE_DISTRIBUTOR, 'budi');

        return compact('gd', 'alfa', 'citra', 'dewi', 'budi');
    }

    private function po(User $pembeli, ?User $penjual, string $status, int $total, array $extra = []): PurchaseOrder
    {
        return PurchaseOrder::create(array_merge([
            'po_number' => 'PO-'.uniqid(), 'created_by' => $pembeli->id, 'user_id' => $pembeli->id, 'seller_id' => $penjual?->id,
            'company_name' => $pembeli->fullname, 'user_role' => $pembeli->role, 'status' => $status, 'total_amount' => $total,
            'order_date' => now()->toDateString(),
        ], $extra));
    }

    public function test_alat_tersedia_sesuai_izin_dan_kepemilikan(): void
    {
        ['alfa' => $alfa, 'budi' => $budi, 'citra' => $citra] = $this->jaringan();

        $this->assertSame(['struktur_jaringan', 'dormansi_member', 'paket_join'], $this->alat($this->user(User::ROLE_SUPER_ADMIN, 'sa')));
        $this->assertSame(['struktur_jaringan', 'dormansi_member', 'paket_join'], $this->alat($this->user(User::ROLE_ADMIN, 'adm')));
        $this->assertSame([], $this->alat($this->user(User::ROLE_GUDANG, 'gdg')));
        $this->assertSame(['jaringan_saya', 'rekrutan_saya', 'pesanan_downline'], $this->alat($alfa)); // punya downline & rekrutan
        $this->assertSame(['pesanan_downline'], $this->alat($budi));                                   // stockist tanpa downline/rekrutan
        $this->assertSame([], $this->alat($citra));                                                     // reseller tanpa downline
    }

    public function test_struktur_jaringan_ringkasan_dan_detail_mitra_tanpa_kontak(): void
    {
        $this->jaringan();
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa');

        $out = $this->pakai('struktur_jaringan', $sa);
        $this->assertSame(6, $out['jumlah_mitra']);
        $this->assertSame(['aktif' => 2, 'nonaktif' => 0], $out['per_tier']['Distributor']);
        $this->assertSame(['jumlah' => 1, 'mitra' => ['BUDI (Distributor)']], $out['belum_ditempatkan']); // GD memang tanpa upline
        $this->assertSame(['upline' => 'ALFA', 'downline_langsung' => 3], $out['upline_downline_terbanyak'][0]);
        $this->assertStringNotContainsString('RAHASIA', json_encode($out));

        $alfa = $this->pakai('struktur_jaringan', $sa, ['cari' => 'alfa']);
        $this->assertSame(['GRANDI (Grand Distributor)', 3, 3], [$alfa['upline'], $alfa['jumlah_downline_langsung'], $alfa['total_seluruh_downline']]);
        $this->assertSame(4, $this->pakai('struktur_jaringan', $sa, ['cari' => 'grandi'])['total_seluruh_downline']);
        $this->assertSame([], $this->pakai('struktur_jaringan', $sa, ['cari' => 'zzz'])['kandidat']);
    }

    public function test_jaringan_saya_hanya_pohon_sendiri(): void
    {
        ['alfa' => $alfa, 'citra' => $citra, 'budi' => $budi] = $this->jaringan();
        $fani = $this->user(User::ROLE_RESELLER_BRONZE, 'fani', ['upline_id' => $budi->id]);
        PartnerSale::create(['sale_number' => 'NS-1', 'user_id' => $citra->id, 'customer_name' => 'Pembeli Rahasia', 'total_amount' => 100000, 'sold_at' => now()->toDateString()]);
        PartnerSale::create(['sale_number' => 'NS-2', 'user_id' => $fani->id, 'customer_name' => 'X', 'total_amount' => 999000, 'sold_at' => now()->toDateString()]);

        $out = $this->pakai('jaringan_saya', $alfa);
        $this->assertSame([3, 1, 100000.0], [$out['total_anggota'], $out['aktif_jualan_30_hari'], $out['omzet_jaringan_bulan_ini']]);
        $this->assertSame(['CITRA', 100000.0, 'Anda'], [$out['anggota'][0]['nama'], $out['anggota'][0]['omzet_bulan_ini'], $out['anggota'][0]['upline']]);
        $json = json_encode($out);
        foreach (['FANI', '999000', 'Pembeli Rahasia', 'RAHASIA'] as $rahasia) {
            $this->assertStringNotContainsString($rahasia, $json);
        }
    }

    public function test_rekrutan_saya_hanya_rekrutan_sendiri_dengan_penghasilan(): void
    {
        ['alfa' => $alfa, 'citra' => $citra, 'gd' => $gd] = $this->jaringan();
        Commission::create(['user_id' => $alfa->id, 'source_user_id' => $citra->id, 'type' => 'join', 'level' => 1, 'rate' => 0, 'base_amount' => 0, 'amount' => 50000, 'status' => 'approved']);
        Commission::create(['user_id' => $alfa->id, 'source_user_id' => $citra->id, 'type' => 'ro_cashback', 'level' => 1, 'rate' => 0, 'base_amount' => 0, 'amount' => 10000, 'status' => 'approved']);
        Commission::create(['user_id' => $gd->id, 'source_user_id' => $alfa->id, 'type' => 'join', 'level' => 1, 'rate' => 0, 'base_amount' => 0, 'amount' => 777000, 'status' => 'approved']);

        $out = $this->pakai('rekrutan_saya', $alfa);
        $this->assertSame([2, 2, 50000.0, 10000.0], [$out['jumlah_rekrutan'], $out['rekrutan_baru_bulan_ini'], $out['total_bonus_join'], $out['total_ro_cashback']]);
        $this->assertSame(60000.0, collect($out['rekrutan'])->firstWhere('nama', 'CITRA')['penghasilan']);
        $this->assertSame(app(CommissionService::class)->availableBalance($alfa), $out['saldo_bisa_ditarik']);
        $this->assertStringNotContainsString('777000', json_encode($out)); // komisi milik upline lain
    }

    public function test_dormansi_member_panel_dan_mitra_paling_lama_tidak_order(): void
    {
        MemberDormancyRule::updateOrCreate(['role' => User::ROLE_DISTRIBUTOR], [
            'enabled' => true, 'basis' => MemberDormancyRule::BASIS_ORDER, 'inactive_months' => 3, 'activated_at' => now()->subYear(),
        ]);
        $lama = $this->user(User::ROLE_DISTRIBUTOR, 'lama');
        $lama->forceFill(['created_at' => now()->subDays(150)])->save();
        $this->po($lama, null, PurchaseOrder::STATUS_COMPLETED, 100000)->forceFill(['created_at' => now()->subDays(85)])->save();
        $baru = $this->user(User::ROLE_DISTRIBUTOR, 'baru');
        $this->po($baru, null, PurchaseOrder::STATUS_COMPLETED, 100000)->forceFill(['created_at' => now()->subDays(2)])->save();
        $nol = $this->user(User::ROLE_DISTRIBUTOR, 'nol');
        $nol->forceFill(['created_at' => now()->subDays(10)])->save();
        $this->user(User::ROLE_DISTRIBUTOR, 'beku', ['status' => User::STATUS_INACTIVE, 'disabled_at' => now()->subDay()]);

        $out = $this->pakai('dormansi_member', $this->user(User::ROLE_ADMIN, 'adm'));
        $this->assertSame(['aktif' => true, 'beku_setelah_bulan' => 3, 'basis' => 'tidak order (PO)'], $out['aturan']['Distributor']);
        $this->assertSame(['LAMA'], array_column($out['akan_dibekukan_14_hari'], 'nama')); // 85 hari lalu + 3 bulan → ≤ 14 hari
        $this->assertSame(1, $out['sudah_dibekukan']['jumlah']);
        $this->assertSame(['NOL', 'LAMA', 'BARU'], array_column($out['mitra_paling_lama_tidak_order'], 'mitra')); // belum pernah order paling atas
        $this->assertSame(['belum pernah order', 85], [$out['mitra_paling_lama_tidak_order'][0]['order_terakhir'], $out['mitra_paling_lama_tidak_order'][1]['hari_sejak_order']]);
    }

    public function test_paket_join_isi_dan_jumlah_bergabung_tanpa_yang_batal(): void
    {
        $p = Product::create(['name' => 'Serum Glow', 'sku' => 'SG-01', 'status' => 'active', 'hq_stock' => 0,
            'price_distributor' => 1, 'price_reseller' => 1, 'price_retail' => 1, 'cogs' => 1]);
        $paket = JoinPackage::create(['name' => 'Paket Distributor', 'target_role' => User::ROLE_DISTRIBUTOR, 'price' => 5000000, 'is_active' => true]);
        $paket->items()->create(['product_id' => $p->id, 'qty' => 10]);
        $m = fn ($u) => $this->user(User::ROLE_DISTRIBUTOR, $u)->id;
        JoinTransaction::create(['user_id' => $m('j1'), 'join_package_id' => $paket->id, 'price' => 5000000]);
        JoinTransaction::create(['user_id' => $m('j2'), 'join_package_id' => $paket->id, 'price' => 5000000, 'cancelled_at' => now()]);
        JoinTransaction::create(['user_id' => $m('j3'), 'join_package_id' => $paket->id, 'price' => 5000000])
            ->forceFill(['created_at' => now()->subMonthNoOverflow()->startOfMonth()])->save();

        $out = $this->pakai('paket_join', $this->user(User::ROLE_ADMIN, 'adm'))['paket'][0];
        $this->assertSame(['Distributor', ['Serum Glow × 10'], 1, 5000000.0, 2],
            [$out['untuk_tier'], $out['isi'], $out['bergabung_periode'], $out['rupiah_periode'], $out['bergabung_total']]);
    }

    public function test_pesanan_downline_hanya_po_di_mana_dia_penjual(): void
    {
        ['alfa' => $alfa, 'citra' => $citra, 'budi' => $budi] = $this->jaringan();
        $fani = $this->user(User::ROLE_RESELLER_BRONZE, 'fani', ['upline_id' => $budi->id]);
        $this->po($citra, $alfa, PurchaseOrder::STATUS_PENDING, 300000, ['payment_status' => PurchaseOrder::PAYMENT_AWAITING]);
        $this->po($citra, $alfa, PurchaseOrder::STATUS_COMPLETED, 200000, ['payment_status' => PurchaseOrder::PAYMENT_PAID]);
        $this->po($citra, $alfa, PurchaseOrder::STATUS_DRAFT, 50000);
        $this->po($fani, $budi, PurchaseOrder::STATUS_PENDING, 999000);

        $out = $this->pakai('pesanan_downline', $alfa);
        $this->assertSame(['jumlah' => 1, 'rupiah' => 300000.0], $out['per_status']['pending']);
        $this->assertCount(1, $out['perlu_tindakan']); // draft & completed tak perlu tindakan penjual
        $this->assertSame(['CITRA', 'bukti bayar menunggu verifikasi Anda'], [$out['perlu_tindakan'][0]['downline'], $out['perlu_tindakan'][0]['pembayaran']]);
        $this->assertCount(3, $out['terbaru']);
        $this->assertStringNotContainsString('FANI', json_encode($out));
        $this->assertNull(app(ToolRegistry::class)->find('pesanan_downline', $this->user(User::ROLE_SUPER_ADMIN, 'sa')));
    }
}
