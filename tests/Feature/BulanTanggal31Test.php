<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\KolContent;
use App\Models\KolGapokPayment;
use App\Models\KolGapokSalary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Pilih bulan di tanggal 29–31: "2026-09" dulu dibaca "31 September" → meluap jadi Oktober (createFromFormat
 * mengisi tanggal dari hari ini). Kini format '!Y-m' (tanggal 1). Paling berbahaya di Tim Gapok: gaji & pembayaran
 * bulan lalu tersimpan di bulan yang salah.
 */
class BulanTanggal31Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_gaji_dan_bayar_telat_gapok_tetap_masuk_bulan_yang_dipilih(): void
    {
        $kol = Kol::create(['tiktok_username' => 'gapok31', 'followers' => 1, 'is_gapok' => true]);
        $spesialis = $this->user('kol_specialist', 'sp31');

        // Tanggal 31 Okt: catat gaji & pembayaran telat untuk performa September (30 hari) dan Februari.
        $this->actingAs($spesialis)->post(route('kol-gapok.salary'), ['kol_id' => $kol->id, 'bulan' => '2026-09', 'monthly_salary' => 2_000_000])
            ->assertRedirect();
        $this->actingAs($spesialis)->postJson(route('kol-gapok.payment'), ['kol_id' => $kol->id, 'bulan' => '2026-09',
            'amount' => 500_000, 'paid_at' => '2026-10-31'])->assertOk()->assertJson(['paid_total' => 500_000]);
        $this->actingAs($spesialis)->post(route('kol-gapok.salary'), ['kol_id' => $kol->id, 'bulan' => '2026-02', 'monthly_salary' => 1_000_000])
            ->assertRedirect();

        $this->assertSame(['2026-02-01', '2026-09-01'], KolGapokSalary::orderBy('period')->pluck('period')->all());
        $bayar = KolGapokPayment::sole();
        $this->assertSame(['2026-09-01', '2026-10-31'], [$bayar->period, $bayar->paid_at->toDateString()]);

        // Halaman September menampilkan gaji & pembayaran September itu.
        $page = $this->actingAs($spesialis)->get(route('kol-gapok.index', ['bulan' => '2026-09']))->assertOk();
        $this->assertSame(['2026-09', '2026-08', '2026-10'], [$page->viewData('month'), $page->viewData('prevMonth'), $page->viewData('nextMonth')]);
        $this->assertSame([2_000_000, 500_000], [$page->viewData('rows')->first()['salary'], $page->viewData('rows')->first()['paid']]);
    }

    public function test_halaman_kol_pilih_bulan_lalu_tidak_lompat_ke_bulan_berikutnya(): void
    {
        $sa = $this->user(User::ROLE_SUPER_ADMIN, 'sa31');
        $kol = Kol::create(['tiktok_username' => 'konten31', 'followers' => 1]);
        KolContent::create(['kol_id' => $kol->id, 'url' => 'https://www.tiktok.com/@konten31/video/1', 'posted_at' => '2026-09-15']);

        foreach (['kol-gapok.index', 'kol-konten.index', 'kol-deals.index', 'kol-dashboard.index', 'kol-affiliate.index', 'kol-affiliate.transactions'] as $route) {
            $page = $this->actingAs($sa)->get(route($route, ['bulan' => '2026-09']))->assertOk();
            $this->assertSame('2026-08', $page->viewData('prevMonth'), "{$route}: bulan sebelum September harus Agustus");
        }
        // Konten September tampil di halaman September (dulu terbaca Oktober → kosong).
        $this->assertCount(1, $this->actingAs($sa)->get(route('kol-konten.index', ['bulan' => '2026-09']))->viewData('contents'));
        $this->assertCount(1, $this->actingAs($sa)->get(route('kol-konten.grid', ['bulan' => '2026-09']))->viewData('contents'));
    }

    public function test_tidak_ada_lagi_parse_bulan_tanpa_tanggal_satu(): void
    {
        // createFromFormat('Y-m') mengisi tanggal dari hari ini → wajib '!Y-m' (atau tambahkan '-01').
        $sisa = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $f) {
            if ($f->isFile() && $f->getExtension() === 'php' && preg_match('/createFromFormat\(\s*[\'"]Y-m[\'"]/', file_get_contents($f->getPathname()))) {
                $sisa[] = $f->getPathname();
            }
        }
        $this->assertSame([], $sisa);
    }
}
