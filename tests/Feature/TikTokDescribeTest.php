<?php

namespace Tests\Feature;

use App\Models\TiktokConnection;
use App\Models\TiktokSettlement;
use App\Services\TikTokClient;
use App\Services\TikTokSyncService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

/**
 * Keterangan pencairan dulu satu-satunya tarikan TikTok yang manual: butuh satu
 * panggilan API per statement, jadi tombolnya dibatasi 60/klik supaya request
 * web tak timeout — dan tumpukannya hanya habis kalau ada yang rajin mengklik.
 */
class TikTokDescribeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function connection(): TiktokConnection
    {
        // Token masih lama berlaku → freshToken() memakainya apa adanya, tanpa
        // refresh. Jadi test ini menguji describe-nya, bukan alur token.
        return TiktokConnection::create([
            'shop_cipher' => 'CIPHER', 'access_token' => 'tok', 'refresh_token' => 'ref',
            'access_expires_at' => now()->addDay(),
            'refresh_expires_at' => now()->addMonth(),
        ]);
    }

    private function settlement(string $id, ?string $kind = null): TiktokSettlement
    {
        return TiktokSettlement::create([
            'tiktok_statement_id' => $id, 'currency' => 'IDR',
            'revenue_amount' => 0, 'fee_amount' => 0, 'adjustment_amount' => -100178,
            'settlement_amount' => -100178, 'statement_time' => now(), 'kind' => $kind,
            'order_ids' => [], 'raw' => [],
        ]);
    }

    public function test_mengisi_keterangan_yang_masih_kosong(): void
    {
        $this->connection();
        $this->settlement('S-1');

        $client = Mockery::mock(TikTokClient::class);

        $client->shouldReceive('getStatementTransactions')->once()
            ->andReturn(['statement_transactions' => [['type' => 'ADS', 'amount' => -100178]]]);
        $this->app->instance(TikTokClient::class, $client);

        $this->assertSame(0, Artisan::call('tiktok:describe'));

        $this->assertNotNull(TiktokSettlement::where('tiktok_statement_id', 'S-1')->value('kind'));
    }

    /**
     * Perintah ini jalan TIAP JAM. Memanggil API cuma untuk menemukan "tak ada
     * kerjaan" membuang kuota yang dibutuhkan sinkron order.
     */
    public function test_tanpa_tunggakan_tidak_menyentuh_api_sama_sekali(): void
    {
        $this->connection();
        $this->settlement('S-DONE', 'Iklan TikTok');   // sudah berketerangan jelas

        $client = Mockery::mock(TikTokClient::class);
        $client->shouldNotReceive('getStatementTransactions');
        $this->app->instance(TikTokClient::class, $client);

        $this->assertSame(0, Artisan::call('tiktok:describe'));
        $this->assertStringContainsString('tak ada yang dikerjakan', Artisan::output());
    }

    /** "Potongan lain" itu tebakan dari agregat, bukan hasil baca rincian. */
    public function test_keterangan_kabur_dicoba_lagi(): void
    {
        $this->connection();
        $this->settlement('S-VAGUE', 'Potongan lain');

        $client = Mockery::mock(TikTokClient::class);

        $client->shouldReceive('getStatementTransactions')->once()
            ->andReturn(['statement_transactions' => [['type' => 'ADS', 'amount' => -100178]]]);
        $this->app->instance(TikTokClient::class, $client);

        Artisan::call('tiktok:describe');

        $this->assertNotSame('Potongan lain', TiktokSettlement::where('tiktok_statement_id', 'S-VAGUE')->value('kind'));
    }

    /**
     * Gagal SEMUA = ada yang rusak (token/scope/rate limit), bukan sepi kerjaan.
     * Versi lama cuma menghitung $failed tanpa jejak: layarnya bilang "0 diisi"
     * tanpa pernah menyebut sebabnya.
     */
    public function test_gagal_semua_keluar_dengan_status_gagal(): void
    {
        $this->connection();
        $this->settlement('S-1');
        $this->settlement('S-2');

        $client = Mockery::mock(TikTokClient::class);

        $client->shouldReceive('getStatementTransactions')->andThrow(new \RuntimeException('403 scope Finance'));
        $this->app->instance(TikTokClient::class, $client);

        $this->assertSame(1, Artisan::call('tiktok:describe'));
        $this->assertStringContainsString('Semua percobaan gagal', Artisan::output());
    }

    public function test_belum_terhubung_dilewati_tanpa_error(): void
    {
        $this->settlement('S-1');

        $this->assertSame(0, Artisan::call('tiktok:describe'));
        $this->assertStringContainsString('Belum terhubung', Artisan::output());
    }

    public function test_limit_membatasi_jumlah_per_jalan(): void
    {
        $this->connection();
        foreach (range(1, 5) as $i) {
            $this->settlement("S-{$i}");
        }

        $client = Mockery::mock(TikTokClient::class);

        $client->shouldReceive('getStatementTransactions')->times(2)
            ->andReturn(['statement_transactions' => [['type' => 'ADS', 'amount' => -100178]]]);
        $this->app->instance(TikTokClient::class, $client);

        Artisan::call('tiktok:describe', ['--limit' => 2]);

        // Sisanya dilaporkan, bukan hilang diam-diam — jalan berikutnya lanjut.
        $this->assertStringContainsString('sisa: 3', Artisan::output());
    }

    public function test_dijadwalkan_tiap_jam(): void
    {
        $jadwal = collect(app(Schedule::class)->events())
            ->map(fn ($e) => $e->command.' | '.$e->expression);

        $this->assertTrue(
            $jadwal->contains(fn ($j) => str_contains($j, 'tiktok:describe') && str_contains($j, '0 * * * *')),
            'tiktok:describe harus terjadwal tiap jam — kalau tidak, keterangan kembali menunggu klik. Jadwal: '.$jadwal->implode(' ;; '),
        );
    }

    public function test_service_dipakai_bersama_controller_dan_cron(): void
    {
        // Kalau tombol dan cron punya salinan logika sendiri, keduanya pelan-pelan
        // berbeda. Keduanya harus lewat method yang sama.
        $this->assertTrue(method_exists(TikTokSyncService::class, 'describeSettlements'));

        $controller = file_get_contents(app_path('Http/Controllers/TikTokController.php'));
        $this->assertStringContainsString('$this->sync->describeSettlements(', $controller);

        // Batasi pemeriksaan ke method-nya saja: settlementDetail memang MASIH
        // memanggil API langsung, dan itu benar — ia menampilkan transaksi mentah
        // satu statement, bukan mengisi keterangan massal.
        $awal = strpos($controller, 'public function describeSettlements');
        $akhir = strpos($controller, 'public function', $awal + 10);
        $method = substr($controller, $awal, $akhir - $awal);

        $this->assertStringNotContainsString('getStatementTransactions', $method);
        $this->assertStringNotContainsString('deriveKind', $method);   // loop lama sudah pindah ke service
    }
}
