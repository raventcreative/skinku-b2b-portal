<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\KolAffiliateTransaction;
use App\Models\KolContentDailySnapshot;
use App\Models\KolDeal;
use App\Models\KolGapokSalary;
use App\Models\KolScore;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Ai\Tools\ToolRegistry;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Alat Asisten AI menu KOL: izin & kolom mengikuti halamannya — Database KOL = kol.view; Views Harian & angka
 * affiliate (GMV/APS/gaji) = + kol.affiliate.view; biaya deal = kol.deal.finance. Kontak pribadi, catatan, &
 * rekening tak pernah dikirim ke AI.
 */
class AiKolToolsTest extends TestCase
{
    use RefreshDatabase;

    private const ALAT = ['views_harian_kol', 'data_kol'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-06 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

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

    /** Nama alat KOL yang tersedia untuk user ini. */
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

    private function snap(Kol $k, string $id, string $tgl, int $views, string $posted, int $gmv = 0, ?string $title = null): void
    {
        KolContentDailySnapshot::create(['kol_id' => $k->id, 'content_id' => $id, 'title' => $title, 'period' => substr($tgl, 0, 8).'01',
            'captured_on' => $tgl, 'posted_at' => $posted, 'views' => $views, 'gmv' => $gmv]);
    }

    private function order(Kol $k, string $id, int $gmv, string $tgl, string $tipe = 'VIDEO', ?string $status = null): void
    {
        KolAffiliateTransaction::create(['platform' => 'tiktok', 'order_id' => $id, 'kol_id' => $k->id, 'gmv' => $gmv,
            'commission' => intdiv($gmv, 10), 'content_type' => $tipe, 'order_date' => $tgl, 'status' => $status]);
    }

    public function test_alat_kol_mengikuti_hak_akses_role(): void
    {
        $this->assertSame(self::ALAT, $this->alat($this->user(User::ROLE_SUPER_ADMIN)));
        $this->assertSame(self::ALAT, $this->alat($this->user('kol_specialist'))); // kol.view + kol.affiliate.view
        $this->assertSame([], $this->alat($this->user(User::ROLE_ADMIN)));          // tak punya menu KOL
        $this->assertSame([], $this->alat($this->user(User::ROLE_DISTRIBUTOR)));

        // Hanya Database KOL (tanpa izin Affiliate) → data_kol saja.
        $this->izinkan(User::ROLE_GUDANG, 'kol.view');
        $this->assertSame(['data_kol'], $this->alat($this->user(User::ROLE_GUDANG)));

        // Izin Affiliate tanpa Database KOL → tetap tak dapat Views Harian (halamannya bersarang di kol.view).
        $this->izinkan(User::ROLE_RESELLER, 'kol.affiliate.view');
        $res = $this->user(User::ROLE_RESELLER);
        $this->assertSame([], $this->alat($res));
        $this->assertNull(app(ToolRegistry::class)->find('views_harian_kol', $res));
    }

    public function test_views_harian_kol_angka_sama_dengan_halaman(): void
    {
        $dewi = Kol::create(['tiktok_username' => 'dewick02', 'name' => 'Dewi', 'followers' => 1]);
        $aini = Kol::create(['tiktok_username' => 'ainnisaja', 'followers' => 1]);
        foreach (['2026-10-01' => 100, '2026-10-02' => 300, '2026-10-03' => 600, '2026-10-04' => 700] as $tgl => $v) {
            $this->snap($dewi, 'V1', $tgl, $v, '2026-08-01 10:00:00', 0, 'Review lama');
            $this->snap($aini, 'W1', $tgl, intdiv($v, 10), '2026-07-01 10:00:00');
        }
        // V2: diupload 2 Okt (di rentang).
        $this->snap($dewi, 'V2', '2026-10-03', 50, '2026-10-02 09:00:00', 20000, 'Video baru');
        $this->snap($dewi, 'V2', '2026-10-04', 90, '2026-10-02 09:00:00', 30000, 'Video baru');
        $super = $this->user(User::ROLE_SUPER_ADMIN);
        $rentang = ['dari' => '2026-10-01', 'sampai' => '2026-10-03'];

        $out = $this->pakai('views_harian_kol', $super, $rentang);

        $this->assertSame('2026-10-01 s/d 2026-10-03', $out['periode']);
        $this->assertSame(['2026-10-01' => 220, '2026-10-02' => 380, '2026-10-03' => 150], $out['views_per_hari']);
        $this->assertSame(['kreator' => 2, 'views' => 750, 'diposting' => 1, 'gmv' => 30000], $out['total']);
        $this->assertSame(['@dewick02', '@ainnisaja'], array_column($out['kreator_teratas'], 'username'));
        $this->assertSame(['username' => '@dewick02', 'nama' => 'Dewi', 'total_views' => 690, 'diposting' => 1, 'gmv' => 30000,
            'hari_terbaik' => ['tanggal' => '2026-10-02', 'views' => 350]], $out['kreator_teratas'][0]);

        // Rincian per video satu kreator (username tak peka huruf besar & '@').
        $out = $this->pakai('views_harian_kol', $super, $rentang + ['username' => '@DEWICK02']);
        $this->assertSame(['username' => '@dewick02', 'nama' => 'Dewi'], $out['kreator']);
        $this->assertSame([690, 1, 30000], [$out['total_views'], $out['diposting'], $out['gmv']]);
        $this->assertSame(['2026-10-01' => 200, '2026-10-02' => 350, '2026-10-03' => 140], $out['views_per_hari']);
        $this->assertSame(['Review lama', 'Video baru'], array_column($out['video_teratas'], 'judul'));
        $this->assertSame(['judul' => 'Video baru', 'tanggal_upload' => '2026-10-02', 'diupload_di_rentang' => true, 'total_views' => 90,
            'gmv' => 30000, 'hari_terbaik' => ['tanggal' => '2026-10-02', 'views' => 50],
            'link' => 'https://www.tiktok.com/@dewick02/video/V2'], $out['video_teratas'][1]);

        // Nama sebagian → satu cocok dipakai; beberapa cocok → AI diminta tanya balik; tak ada → error.
        $this->assertSame('@dewick02', $this->pakai('views_harian_kol', $super, $rentang + ['username' => 'dewi'])['kreator']['username']);
        Kol::create(['tiktok_username' => 'dewi_sartika', 'followers' => 1]);
        $ganda = $this->pakai('views_harian_kol', $super, $rentang + ['username' => 'dewi']);
        $this->assertArrayHasKey('error', $ganda);
        $this->assertCount(2, $ganda['kandidat']);
        $this->assertArrayHasKey('error', $this->pakai('views_harian_kol', $super, $rentang + ['username' => 'tidakada']));
    }

    public function test_data_kol_profil_kolom_mengikuti_izin_tanpa_kontak_pribadi(): void
    {
        $kol = Kol::create(['tiktok_username' => 'dewick02', 'name' => 'Dewi', 'followers' => 250000, 'role' => 'both',
            'status' => Kol::STATUS_AKTIF, 'kategori' => 'Skinfluencer', 'is_gapok' => true, 'gapok_joined_at' => '2026-08-01',
            'phone' => '081299990000', 'manager_name' => 'Pak Manajer', 'manager_contact' => '089877776666',
            'catatan' => 'catatan internal rahasia']);
        KolDeal::create(['kode' => KolDeal::generateKode(), 'kol_id' => $kol->id, 'jenis' => 'vt', 'status' => 'berjalan',
            'ratecard_deal' => 1500000, 'total_biaya' => 5000000, 'status_bayar' => 'dp',
            'no_rekening' => '5550001112', 'bank' => 'BankRahasia', 'atas_nama' => 'Dewi Rekening']);
        KolScore::create(['kol_id' => $kol->id, 'type' => 'kss', 'score' => 72.5, 'label' => 'shortlist', 'captured_on' => '2026-10-01']);
        KolGapokSalary::create(['kol_id' => $kol->id, 'period' => '2026-10-01', 'monthly_salary' => 1000000]);
        $this->order($kol, 'O1', 150000, '2026-10-03');
        $this->order($kol, 'O2', 50000, '2026-10-04', 'LIVE');
        $this->order($kol, 'O3', 999999, '2026-10-04', 'VIDEO', 'cancelled'); // batal → tak dihitung
        $this->order($kol, 'O4', 70000, '2026-09-20');                        // bulan lalu
        $rahasia = ['081299990000', 'Pak Manajer', '089877776666', 'catatan internal rahasia', '5550001112', 'BankRahasia', 'Dewi Rekening'];

        // 1) Hanya Database KOL (kol.view): profil, KSS, deal tanpa biaya — tanpa GMV/APS/gaji.
        $this->izinkan(User::ROLE_GUDANG, 'kol.view');
        $out = $this->pakai('data_kol', $this->user(User::ROLE_GUDANG), ['username' => 'dewick02']);
        $this->assertSame(['@dewick02', 'Middle', 'KOL + Affiliate'], [$out['kreator']['username'], $out['kreator']['level'], $out['kreator']['peran']]);
        $this->assertSame(['skor' => 72.5, 'label' => 'Shortlist', 'tanggal' => '2026-10-01'], $out['skor_kss']);
        $this->assertSame(1, $out['deal']['jumlah']);
        $this->assertSame(1500000, $out['deal']['terbaru'][0]['ratecard']);
        $this->assertArrayNotHasKey('total_biaya', $out['deal']['terbaru'][0]);
        $this->assertArrayNotHasKey('performa_bulan', $out);
        $this->assertArrayNotHasKey('skor_aps_saat_ini', $out);
        $this->tanpaRahasia($out, $rahasia);

        // 2) kol_specialist (+ kol.affiliate.view): angka bulan ini sama dgn Tim Gapok (batal & bulan lalu tak ikut).
        $out = $this->pakai('data_kol', $this->user('kol_specialist'), ['username' => 'dewick02']);
        $this->assertSame(['bulan' => '2026-10', 'gmv' => 200000, 'pesanan' => 2, 'komisi' => 20000, 'gmv_live' => 50000,
            'gmv_video' => 150000, 'jumlah_video' => 0, 'jumlah_live' => 0, 'gaji_gapok' => 1000000, 'roi_gapok' => 0.2], $out['performa_bulan']);
        $this->assertArrayHasKey('skor_aps_saat_ini', $out);
        $this->assertArrayNotHasKey('total_biaya', $out['deal']['terbaru'][0]);
        $this->tanpaRahasia($out, $rahasia);

        // Bulan lain lewat parameter.
        $sep = $this->pakai('data_kol', $this->user('kol_specialist', 'ks_sep'), ['username' => 'dewick02', 'bulan' => '2026-09']);
        $this->assertSame([70000, 1], [$sep['performa_bulan']['gmv'], $sep['performa_bulan']['pesanan']]);

        // 3) + Finansial Deal → biaya & status bayar tampil; rekening tetap tidak.
        $this->izinkan('kol_specialist', 'kol.deal.finance');
        $out = $this->pakai('data_kol', $this->user('kol_specialist', 'ks_fin'), ['username' => 'dewick02']);
        $this->assertSame([5000000, 'dp'], [$out['deal']['terbaru'][0]['total_biaya'], $out['deal']['terbaru'][0]['status_bayar']]);
        $this->tanpaRahasia($out, $rahasia);
    }

    public function test_data_kol_daftar_ringkasan_filter_dan_urut(): void
    {
        Kol::create(['tiktok_username' => 'besar', 'followers' => 900000, 'role' => 'kol', 'status' => Kol::STATUS_AKTIF]);
        $laris = Kol::create(['tiktok_username' => 'laris', 'followers' => 5000, 'role' => 'affiliate', 'status' => Kol::STATUS_AKTIF, 'is_gapok' => true]);
        $pros = Kol::create(['tiktok_username' => 'prospek1', 'followers' => 20000, 'role' => 'affiliate', 'status' => Kol::STATUS_PROSPEK]);
        $this->order($laris, 'L1', 300000, '2026-10-02');
        $this->order($pros, 'P1', 100000, '2026-10-03');

        // Dengan izin Affiliate: urut GMV bulan ini.
        $out = $this->pakai('data_kol', $this->user('kol_specialist'));
        $this->assertSame(3, $out['ringkasan']['jumlah_kol']);
        $this->assertSame(1, $out['ringkasan']['tim_gapok']);
        $this->assertSame(400000, $out['ringkasan']['gmv_bulan']);
        $this->assertSame('gmv', $out['urut']);
        $this->assertSame(['@laris', '@prospek1', '@besar'], array_column($out['daftar'], 'username'));
        $this->assertSame(300000, $out['daftar'][0]['gmv']);

        // Status & kategori bukan filter (di data nyata semua "prospek" & kategori kosong — AI yang memfilter dgn itu
        // selalu dapat daftar kosong) → diabaikan, daftar tetap penuh.
        $out = $this->pakai('data_kol', $this->user('kol_specialist', 'ks2'), ['status' => Kol::STATUS_HOLD, 'kategori' => 'Makeup']);
        $this->assertSame(['@laris', '@prospek1', '@besar'], array_column($out['daftar'], 'username'));
        $this->assertArrayNotHasKey('filter', $out);

        // Saring peran — sebaran semua KOL ikut dikirim supaya AI bisa menilai saringannya.
        $out = $this->pakai('data_kol', $this->user('kol_specialist', 'ks3'), ['peran' => 'affiliate']);
        $this->assertSame(['@laris', '@prospek1'], array_column($out['daftar'], 'username'));
        $this->assertSame(3, $out['semua_kol']['jumlah_kol']);
        $this->assertArrayNotHasKey('catatan', $out);

        // Saringan yang tak cocok satu pun → daftar kosong + catatan agar AI mengulang tanpa filter.
        $out = $this->pakai('data_kol', $this->user('kol_specialist', 'ks4'), ['peran' => 'both']);
        $this->assertSame([], $out['daftar']);
        $this->assertStringContainsString('ulangi TANPA filter', $out['catatan']);
        $this->assertSame(['KOL' => 1, 'Affiliate' => 2], $out['semua_kol']['per_peran']);

        // Tanpa izin Affiliate: diminta urut GMV tetap diurut followers, tanpa angka GMV sama sekali.
        $this->izinkan(User::ROLE_GUDANG, 'kol.view');
        $out = $this->pakai('data_kol', $this->user(User::ROLE_GUDANG), ['urut' => 'gmv']);
        $this->assertSame('followers', $out['urut']);
        $this->assertSame(['@besar', '@prospek1', '@laris'], array_column($out['daftar'], 'username'));
        $this->assertArrayNotHasKey('gmv_bulan', $out['ringkasan']);
        $this->assertArrayNotHasKey('gmv', $out['daftar'][0]);
        $this->assertStringNotContainsString('300000', json_encode($out));
    }

    private function tanpaRahasia(array $out, array $rahasia): void
    {
        $json = json_encode($out, JSON_UNESCAPED_UNICODE);
        foreach ($rahasia as $r) {
            $this->assertStringNotContainsString($r, $json, "data pribadi '{$r}' tak boleh dikirim ke AI");
        }
    }
}
