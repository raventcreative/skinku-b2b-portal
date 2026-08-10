<?php

namespace Tests\Feature\ReportBot;

use App\Models\AppSetting;
use App\Models\TelegramBotChat;
use App\Services\ReportBot\Flows\AdsReportFlow;
use App\Services\ReportBot\Flows\LeadsReportFlow;
use App\Services\ReportBot\Flows\TikTokIncomeFlow;
use App\Services\ReportBot\ReportBotDispatcher;
use App\Services\ReportBot\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class DispatcherTest extends TestCase
{
    use RefreshDatabase;

    /** Bentuk minimal update Telegram (message.chat.id/from/text/document), field kosong dibuang. */
    private function update(int $chatId, ?string $text = null, ?array $document = null): array
    {
        return [
            'update_id' => 1,
            'message' => array_filter([
                'message_id' => 1,
                'from' => ['id' => $chatId, 'first_name' => 'Budi'],
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'date' => time(),
                'text' => $text,
                'document' => $document,
            ], fn ($v) => $v !== null),
        ];
    }

    /** Bind mock TelegramClient ke container supaya dispatcher tidak memanggil API asli. */
    private function fakeTelegram(): TelegramClient
    {
        $mock = Mockery::mock(TelegramClient::class);
        $this->app->instance(TelegramClient::class, $mock);

        return $mock;
    }

    /** Bind mock ke container utk kelas flow $class (Leads/Ads/TikTokIncome); balikin mock-nya utk shouldReceive/shouldNotReceive. */
    private function fakeFlow(string $class): MockInterface
    {
        $mock = Mockery::mock($class);
        $this->app->instance($class, $mock);

        return $mock;
    }

    public function test_chat_aktif_kirim_dokumen_leads_memicu_flow_leads(): void
    {
        TelegramBotChat::create(['chat_id' => '111', 'name' => 'Budi', 'authorized_at' => now()]);
        $this->fakeTelegram();

        $document = ['file_name' => 'leads.pdf', 'mime_type' => 'application/pdf', 'file_id' => 'FILE123'];

        $this->fakeFlow(LeadsReportFlow::class)
            ->shouldReceive('handle')
            ->once()
            ->withArgs(fn ($chatId, $doc) => (string) $chatId === '111' && $doc === $document);

        $this->fakeFlow(AdsReportFlow::class)->shouldNotReceive('handle');
        $this->fakeFlow(TikTokIncomeFlow::class)->shouldNotReceive('handle');

        app(ReportBotDispatcher::class)->handle($this->update(111, null, $document));
    }

    public function test_chat_aktif_kirim_dokumen_ads_memicu_flow_ads(): void
    {
        TelegramBotChat::create(['chat_id' => '112', 'name' => 'Budi', 'authorized_at' => now()]);
        $this->fakeTelegram();

        $document = [
            'file_name' => 'ads.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'file_id' => 'FILE124',
        ];

        $this->fakeFlow(AdsReportFlow::class)
            ->shouldReceive('handle')
            ->once()
            ->withArgs(fn ($chatId, $doc) => (string) $chatId === '112' && $doc === $document);

        $this->fakeFlow(LeadsReportFlow::class)->shouldNotReceive('handle');
        $this->fakeFlow(TikTokIncomeFlow::class)->shouldNotReceive('handle');

        app(ReportBotDispatcher::class)->handle($this->update(112, null, $document));
    }

    public function test_chat_aktif_kirim_dokumen_csv_memicu_flow_tiktok_income(): void
    {
        TelegramBotChat::create(['chat_id' => '113', 'name' => 'Budi', 'authorized_at' => now()]);
        $this->fakeTelegram();

        $document = ['file_name' => 'pesanan.csv', 'mime_type' => 'text/csv', 'file_id' => 'FILE125'];

        $this->fakeFlow(TikTokIncomeFlow::class)
            ->shouldReceive('handle')
            ->once()
            ->withArgs(fn ($chatId, $doc) => (string) $chatId === '113' && $doc === $document);

        $this->fakeFlow(LeadsReportFlow::class)->shouldNotReceive('handle');
        $this->fakeFlow(AdsReportFlow::class)->shouldNotReceive('handle');

        app(ReportBotDispatcher::class)->handle($this->update(113, null, $document));
    }

    public function test_chat_baru_kirim_halo_diminta_kode_akses(): void
    {
        $mock = $this->fakeTelegram();
        $mock->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn ($chatId, $text) => (string) $chatId === '222' && str_contains(strtolower($text), 'kode'));

        app(ReportBotDispatcher::class)->handle($this->update(222, 'halo'));

        $this->assertTrue(TelegramBotChat::where('chat_id', '222')->exists());
    }

    public function test_kode_salah_dari_chat_yang_sudah_pernah_kontak(): void
    {
        TelegramBotChat::create(['chat_id' => '333', 'name' => 'Budi']);

        $mock = $this->fakeTelegram();
        $mock->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn ($chatId, $text) => (string) $chatId === '333' && str_contains(strtolower($text), 'salah'));

        app(ReportBotDispatcher::class)->handle($this->update(333, 'bukan-kode'));
    }

    public function test_chat_diblokir_diabaikan_tanpa_balasan(): void
    {
        TelegramBotChat::create(['chat_id' => '444', 'name' => 'Budi', 'is_blocked' => true]);

        // Sengaja tidak ada shouldReceive('sendMessage') — panggilan apa pun ke
        // mock ini yang tak diharapkan akan membuat Mockery gagal saat close().
        $this->fakeTelegram();

        app(ReportBotDispatcher::class)->handle($this->update(444, 'apa saja'));

        $this->assertTrue(true); // no exception dari mock berarti tidak ada sendMessage terpanggil
    }

    public function test_chat_aktif_kirim_dokumen_tak_dikenal(): void
    {
        TelegramBotChat::create(['chat_id' => '555', 'name' => 'Budi', 'authorized_at' => now()]);

        $mock = $this->fakeTelegram();
        $mock->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn ($chatId, $text) => (string) $chatId === '555' && str_contains(strtolower($text), 'belum dikenali'));

        app(ReportBotDispatcher::class)->handle($this->update(555, null, [
            'file_name' => 'random.bin',
            'mime_type' => 'application/octet-stream',
            'file_id' => 'FILE999',
        ]));
    }

    public function test_chat_aktif_kirim_teks_tanpa_dokumen_diminta_kirim_file(): void
    {
        TelegramBotChat::create(['chat_id' => '666', 'name' => 'Budi', 'authorized_at' => now()]);

        $mock = $this->fakeTelegram();
        $mock->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn ($chatId, $text) => (string) $chatId === '666' && str_contains(strtolower($text), 'kirim file'));

        app(ReportBotDispatcher::class)->handle($this->update(666, 'halo lagi'));
    }

    public function test_kode_baru_benar_dapat_pesan_aktif_sebelum_diminta_kirim_file(): void
    {
        AppSetting::put('report_bot_access_code', 'BUKA123');
        TelegramBotChat::create(['chat_id' => '777', 'name' => 'Budi']); // sudah pernah kontak, belum aktif

        $mock = $this->fakeTelegram();
        $mock->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn ($chatId, $text) => (string) $chatId === '777'
                && str_contains($text, 'aktif')
                && str_contains(strtolower($text), 'kirim file'));

        app(ReportBotDispatcher::class)->handle($this->update(777, 'BUKA123'));
    }

    public function test_webhook_end_to_end_memicu_dispatcher(): void
    {
        config()->set('services.telegram.webhook_secret', 'rahasia123');

        $mock = $this->fakeTelegram();
        $mock->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn ($chatId, $text) => (string) $chatId === '888' && str_contains(strtolower($text), 'kode'));

        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'rahasia123'])
            ->postJson('/telegram/webhook', $this->update(888, 'halo'))
            ->assertOk();
    }
}
