<?php

namespace Tests\Feature;

use App\Models\Kol;
use App\Models\KolAffiliateTransaction;
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

    public function test_match_via_http_butuh_manage(): void
    {
        $spec = $this->user('kol_specialist', 'aff5');
        $kol = Kol::create(['tiktok_username' => 'newmatch', 'followers' => 8000]);
        $this->svc()->import([['order_id' => 'M1', 'username' => 'orang_asing', 'gmv' => 200_000, 'order_date' => now()->toDateString()]], 'tiktok', $spec->id);

        $this->actingAs($spec)->post(route('kol-affiliate.match'), ['raw_username' => 'orang_asing', 'kol_id' => $kol->id])->assertRedirect();
        $this->assertSame($kol->id, KolAffiliateTransaction::where('order_id', 'M1')->value('kol_id'));
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
}
