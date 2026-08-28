<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Kol;
use App\Models\KolAffiliateTransaction;
use App\Models\KolImportBatch;
use App\Models\KolWeeklyStat;
use App\Models\User;
use App\Services\KolAffiliateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class KolAffiliateTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => strtoupper($u), 'username' => $u, 'email' => "{$u}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function svc(): KolAffiliateService
    {
        return app(KolAffiliateService::class);
    }

    public function test_import_dedup_match_dan_unmatched(): void
    {
        $kol = Kol::create(['tiktok_username' => 'ayu.skin', 'followers' => 50_000]);
        $actor = $this->user('kol_specialist', 'aff1')->id;

        $rows = [
            ['order_id' => 'A1', 'username' => '@ayu.skin', 'gmv' => 100_000, 'commission' => 10_000, 'order_date' => now()->toDateString()],
            ['order_id' => 'A2', 'username' => 'siapa_ini', 'gmv' => 50_000, 'order_date' => now()->toDateString()], // unmatched
            ['order_id' => '', 'username' => 'x', 'gmv' => 9], // tanpa order_id → dilewati
        ];
        $res = $this->svc()->import($rows, 'tiktok', $actor);
        $this->assertSame(['imported' => 2, 'matched' => 1, 'unmatched' => 1], $res);
        $this->assertSame($kol->id, KolAffiliateTransaction::where('order_id', 'A1')->value('kol_id'));

        // Re-import order sama (A1) dgn GMV baru → replace, bukan dobel.
        $this->svc()->import([['order_id' => 'A1', 'username' => 'ayu.skin', 'gmv' => 250_000, 'order_date' => now()->toDateString()]], 'tiktok', $actor);
        $this->assertSame(2, KolAffiliateTransaction::count()); // tetap 2 baris
        $this->assertSame(250_000, (int) KolAffiliateTransaction::where('order_id', 'A1')->value('gmv'));
    }

    public function test_monthly_kecualikan_batal_dan_unmatched(): void
    {
        $kol = Kol::create(['tiktok_username' => 'budi', 'followers' => 10_000]);
        $actor = $this->user('kol_specialist', 'aff2')->id;
        $this->svc()->import([
            ['order_id' => 'B1', 'username' => 'budi', 'gmv' => 200_000, 'commission' => 20_000, 'order_date' => now()->toDateString()],
            ['order_id' => 'B2', 'username' => 'budi', 'gmv' => 999_000, 'status' => 'Cancelled', 'order_date' => now()->toDateString()], // batal → tak dihitung
            ['order_id' => 'B3', 'username' => 'ga_kenal', 'gmv' => 500_000, 'order_date' => now()->toDateString()], // unmatched → tak masuk ranking
        ], 'tiktok', $actor);

        $rank = $this->svc()->monthly(now());
        $this->assertCount(1, $rank);
        $this->assertSame($kol->id, (int) $rank[0]->kol_id);
        $this->assertSame(200_000, (int) $rank[0]->gmv);   // B2 batal dikecualikan
        $this->assertSame(1, (int) $rank[0]->orders);
    }

    public function test_weekly_gmv_dan_match_username(): void
    {
        $kol = Kol::create(['tiktok_username' => 'sari', 'followers' => 20_000]);
        $actor = $this->user('kol_specialist', 'aff3')->id;
        // Minggu ini 300rb, minggu lalu 100rb.
        $this->svc()->import([
            ['order_id' => 'C1', 'username' => 'sari', 'gmv' => 300_000, 'order_date' => now()->toDateString()],
            ['order_id' => 'C2', 'username' => 'sari', 'gmv' => 100_000, 'order_date' => now()->subWeek()->toDateString()],
        ], 'tiktok', $actor);

        $weekly = $this->svc()->weeklyGmv($kol->id, now(), 4);
        $this->assertSame([0, 0, 100_000, 300_000], $weekly); // lama → baru

        // Cocokkan username belum kenal ke KOL baru.
        $this->svc()->import([['order_id' => 'D1', 'username' => 'dewi_glow', 'gmv' => 80_000, 'order_date' => now()->toDateString()]], 'tiktok', $actor);
        $this->assertSame(1, $this->svc()->unmatched()->count());
        $dewi = Kol::create(['tiktok_username' => 'dewi_glow', 'followers' => 5000]);
        $linked = $this->svc()->matchUsername('dewi_glow', $dewi->id);
        $this->assertSame(1, $linked);
        $this->assertSame(0, $this->svc()->unmatched()->count());
    }

    public function test_halaman_affiliate_gating_dan_ranking(): void
    {
        // Tanpa kol.affiliate.view → 403 (gudang: bukan grantee).
        $this->actingAs($this->user(User::ROLE_GUDANG, 'gd1'))
            ->get(route('kol-affiliate.index'))->assertForbidden();

        $spec = $this->user('kol_specialist', 'aff4');
        $kol = Kol::create(['tiktok_username' => 'ranktest', 'followers' => 30_000]);
        $this->svc()->import([['order_id' => 'R1', 'username' => 'ranktest', 'gmv' => 1_000_000, 'order_date' => now()->toDateString()]], 'tiktok', $spec->id);

        $this->actingAs($spec)->get(route('kol-affiliate.index'))->assertOk()
            ->assertSee('Affiliate & GMV')->assertSee('ranktest');
    }

    public function test_monthly_weekly_gmv_agregat_kecualikan_batal(): void
    {
        $kol = Kol::create(['tiktok_username' => 'weekkol', 'followers' => 10_000]);
        $actor = $this->user('kol_specialist', 'affw')->id;
        $d = now()->startOfMonth()->addDays(2)->toDateString();
        $this->svc()->import([
            ['order_id' => 'W1', 'username' => 'weekkol', 'gmv' => 300_000, 'order_date' => $d],
            ['order_id' => 'W2', 'username' => 'weekkol', 'gmv' => 500_000, 'order_date' => $d],
            ['order_id' => 'W3', 'username' => 'weekkol', 'gmv' => 999_000, 'status' => 'Batal', 'order_date' => $d],
        ], 'tiktok', $actor);

        $weekly = $this->svc()->monthlyWeeklyGmv(now());
        $this->assertNotEmpty($weekly);
        $this->assertArrayHasKey('label', $weekly[0]);
        $this->assertArrayHasKey('gmv', $weekly[0]);
        $this->assertSame(800_000, collect($weekly)->sum('gmv')); // W3 batal dikecualikan
    }

    public function test_halaman_transaksi_gating_filter_dan_render(): void
    {
        // Tanpa kol.affiliate.view → 403.
        $this->actingAs($this->user(User::ROLE_GUDANG, 'gdt'))->get(route('kol-affiliate.transactions'))->assertForbidden();

        $spec = $this->user('kol_specialist', 'afft');
        Kol::create(['tiktok_username' => 'txkol', 'followers' => 20_000]);
        $this->svc()->import([
            ['order_id' => 'T1', 'username' => 'txkol', 'gmv' => 100_000, 'product' => 'Serum A', 'qty' => 2, 'order_date' => now()->toDateString()],
            ['order_id' => 'T2', 'username' => 'txkol', 'gmv' => 999_000, 'status' => 'Cancelled', 'order_date' => now()->toDateString()],
        ], 'tiktok', $spec->id);
        $this->svc()->import([
            ['order_id' => 'S1', 'username' => 'txkol', 'gmv' => 50_000, 'order_date' => now()->toDateString()],
        ], 'shopee', $spec->id);

        // Semua: order id per-baris tampil (data yang dulu cuma diagregat).
        $this->actingAs($spec)->get(route('kol-affiliate.transactions'))->assertOk()
            ->assertSee('Transaksi Affiliate')->assertSee('T1')->assertSee('Serum A')->assertSee('S1');

        // Filter platform=shopee → hanya order shopee.
        $this->actingAs($spec)->get(route('kol-affiliate.transactions', ['platform' => 'shopee']))->assertOk()
            ->assertSee('S1')->assertDontSee('>T1<', false);

        // Filter status=cancelled → hanya order batal.
        $this->actingAs($spec)->get(route('kol-affiliate.transactions', ['status' => 'cancelled']))->assertOk()
            ->assertSee('T2')->assertDontSee('>S1<', false);
    }

    public function test_match_via_http_butuh_manage(): void
    {
        $spec = $this->user('kol_specialist', 'aff5');
        $kol = Kol::create(['tiktok_username' => 'newmatch', 'followers' => 8000]);
        $this->svc()->import([['order_id' => 'M1', 'username' => 'orang_asing', 'gmv' => 200_000, 'order_date' => now()->toDateString()]], 'tiktok', $spec->id);

        $this->actingAs($spec)->post(route('kol-affiliate.match'), ['raw_username' => 'orang_asing', 'kol_id' => $kol->id])->assertRedirect();
        $this->assertSame($kol->id, KolAffiliateTransaction::where('order_id', 'M1')->value('kol_id'));
    }

    public function test_weekly_stat_dan_gmv_target(): void
    {
        $spec = $this->user('kol_specialist', 'affws');
        $kol = Kol::create(['tiktok_username' => 'wskol', 'followers' => 10_000]);

        $this->actingAs($spec)->post(route('kol-affiliate.gmv-target'), ['gmv_target' => 50_000_000])->assertRedirect();
        $this->assertSame('50000000', AppSetting::get('kol_gmv_target'));

        $this->actingAs($spec)->post(route('kol-affiliate.weekly.store'), [
            'kol_id' => $kol->id, 'week_start' => now()->startOfWeek()->toDateString(), 'gmv' => 1_000_000, 'orders' => 5, 'views' => 20_000,
        ])->assertRedirect();
        $ws = KolWeeklyStat::first();
        $this->assertSame(1_000_000, $ws->gmv);

        $this->actingAs($spec)->delete(route('kol-affiliate.weekly.destroy', $ws))->assertRedirect();
        $this->assertSame(0, KolWeeklyStat::count());
    }

    public function test_import_log_batch_dan_komisi_settled(): void
    {
        $spec = $this->user('kol_specialist', 'affib');
        Kol::create(['tiktok_username' => 'ibkol', 'followers' => 10_000]);
        $csv = "Order ID,Creator Username,Total,Komisi,Actual Commission,Status,Tanggal\n"
            ."Z1,@ibkol,100000,10000,9500,Completed,2026-08-20\n";
        $file = UploadedFile::fake()->createWithContent('aff.csv', $csv);

        $this->actingAs($spec)->post(route('kol-affiliate.import.store'), ['platform' => 'tiktok', 'file' => $file])
            ->assertRedirect(route('kol-affiliate.index'));

        $t = KolAffiliateTransaction::where('order_id', 'Z1')->first();
        $this->assertSame(9500, (int) $t->commission_settled);
        $this->assertSame(1, KolImportBatch::count());
        $this->assertSame(1, (int) KolImportBatch::first()->imported);
    }

    public function test_import_csv_auto_map_header(): void
    {
        $spec = $this->user('kol_specialist', 'aff6');
        Kol::create(['tiktok_username' => 'csvkol', 'followers' => 12_000]);

        // Header pakai nama umum Affiliate Center → auto-map.
        $csv = "Order ID,Creator Username,Total Penjualan,Komisi,Status,Tanggal\n"
            ."X100,@csvkol,\"Rp 150.000\",15000,Completed,2026-08-20\n"
            ."X101,belum_kenal,90000,9000,Completed,2026-08-21\n";
        $file = UploadedFile::fake()->createWithContent('aff.csv', $csv);

        $this->actingAs($spec)->post(route('kol-affiliate.import.store'), ['platform' => 'tiktok', 'file' => $file])
            ->assertRedirect(route('kol-affiliate.index'));

        $this->assertSame(2, KolAffiliateTransaction::count());
        $t = KolAffiliateTransaction::where('order_id', 'X100')->first();
        $this->assertSame(150_000, (int) $t->gmv);              // "Rp 150.000" → 150000
        $this->assertNotNull($t->kol_id);                       // @csvkol cocok
        $this->assertSame('2026-08-20', $t->order_date->format('Y-m-d'));
        $this->assertNull(KolAffiliateTransaction::where('order_id', 'X101')->value('kol_id')); // belum cocok
    }

    public function test_import_wizard_preview_lalu_commit_mapping_manual(): void
    {
        $spec = $this->user('kol_specialist', 'affwz');
        Kol::create(['tiktok_username' => 'wzkol', 'followers' => 20_000]);

        // Header sengaja non-standar → auto-map gagal, wizard petakan manual.
        $csv = "Kode Pesanan,Nama TT,Omzet Jual,Tgl\n"
            ."W1,wzkol,150000,03/04/2026\n";
        $file = UploadedFile::fake()->createWithContent('aff.csv', $csv);

        // Langkah 1 — preview membuka wizard + menyimpan baris ke temp (token).
        $prev = $this->actingAs($spec)->post(route('kol-affiliate.import.preview'), ['platform' => 'tiktok', 'file' => $file]);
        $prev->assertOk()->assertSee('Pemetaan Kolom')->assertSee('Preview 20 Baris Pertama');
        $token = $prev->viewData('token');
        $this->assertNotEmpty($token);

        // Langkah 2 — commit mapping manual + dateOrder dmy (03/04 = 3 Apr, bukan 4 Mar).
        $this->actingAs($spec)->post(route('kol-affiliate.import.commit'), [
            'token' => $token, 'platform' => 'tiktok', 'filename' => 'aff.csv', 'date_order' => 'dmy',
            'map' => ['order_id' => 0, 'username' => 1, 'gmv' => 2, 'order_date' => 3],
        ])->assertRedirect(route('kol-affiliate.index'));

        $t = KolAffiliateTransaction::where('order_id', 'W1')->first();
        $this->assertNotNull($t);
        $this->assertSame(150_000, (int) $t->gmv);
        $this->assertNotNull($t->kol_id);                                // wzkol cocok
        $this->assertSame('2026-04-03', $t->order_date->format('Y-m-d')); // dmy tegas
        $this->assertNotNull(AppSetting::get('kol_import_map_tiktok'));   // mapping diingat
        $this->assertSame('dmy', AppSetting::get('kol_import_date_order'));
        $this->assertSame('wizard', KolImportBatch::latest('id')->first()->source);
    }

    public function test_import_wizard_wajib_order_id_dan_username(): void
    {
        $spec = $this->user('kol_specialist', 'affwz2');
        $file = UploadedFile::fake()->createWithContent('aff.csv', "A,B,C\nfoo,bar,baz\n");

        $token = $this->actingAs($spec)->post(route('kol-affiliate.import.preview'), ['platform' => 'tiktok', 'file' => $file])
            ->viewData('token');

        // Commit tanpa memetakan order_id/username → ditolak, tak ada yang masuk.
        $this->actingAs($spec)->post(route('kol-affiliate.import.commit'), [
            'token' => $token, 'platform' => 'tiktok', 'date_order' => 'auto', 'map' => ['gmv' => 2],
        ])->assertSessionHasErrors('map');
        $this->assertSame(0, KolAffiliateTransaction::count());
    }
}
