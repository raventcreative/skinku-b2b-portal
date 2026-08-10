<?php

namespace Tests\Feature\ReportBot;

use App\Services\Ai\AiException;
use App\Services\ReportBot\Flows\LeadsReportFlow;
use App\Services\ReportBot\ReportAi;
use App\Services\ReportBot\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 9: LeadsReportFlow — unduh PDF (TelegramClient) -> ReportAi::readFile
 * (multimodal, JSON per-CS) -> agregasi PHP (port node n8n "Code in parse")
 * -> ReportAi::analyze per CS (system prompt verbatim dari "AI Daily Report
 * Analyzer") -> gabung HTML (port "Code in Generate HTML") ->
 * TelegramClient::sendDocument. ReportAi & TelegramClient di-mock lewat
 * container binding — tidak ada panggilan API asli sama sekali.
 */
class LeadsFlowTest extends TestCase
{
    use RefreshDatabase;

    private function document(): array
    {
        return [
            'file_id' => 'FILE123',
            'file_name' => 'leads.pdf',
            'mime_type' => 'application/pdf',
        ];
    }

    /** Bind mock TelegramClient dengan getFile/downloadFile sudah dikanibalisasi. */
    private function fakeTelegram(): MockInterface
    {
        $telegram = Mockery::mock(TelegramClient::class);
        $telegram->shouldReceive('getFile')
            ->once()
            ->with('FILE123')
            ->andReturn(['ok' => true, 'result' => ['file_path' => 'documents/leads.pdf']]);
        $telegram->shouldReceive('downloadFile')
            ->once()
            ->with('documents/leads.pdf')
            ->andReturn('%PDF-FAKE-BYTES%');

        $this->app->instance(TelegramClient::class, $telegram);

        return $telegram;
    }

    /** Contoh JSON 2 CS persis bentuk yang diminta instruction readFile: {csName, dailyRows[], total{}}. */
    private function twoCsJson(): array
    {
        return [
            [
                'csName' => 'Siti',
                // Sengaja dibalik (02 duluan) supaya test membuktikan aggregate() betul2 sort by date,
                // bukan cuma ambil elemen array terakhir apa adanya.
                'dailyRows' => [
                    ['date' => '2025-03-02', 'leads' => 10, 'closing' => 2, 'rate' => 20, 'omzet' => 2000000, 'jumlahDress' => 4, 'avgSales' => 500000, 'avgDress' => 2, 'dressRate' => 40],
                    ['date' => '2025-03-01', 'leads' => 12, 'closing' => 3, 'rate' => 25, 'omzet' => 2800000, 'jumlahDress' => 5, 'avgSales' => 466666, 'avgDress' => 1.67, 'dressRate' => 41.7],
                ],
                'total' => ['leads' => 22, 'closing' => 5, 'rate' => 22.7, 'omzet' => 4800000, 'jumlahDress' => 9, 'avgSales' => 480000, 'avgDress' => 1.8, 'dressRate' => 40.9],
            ],
            [
                'csName' => 'Rudi',
                'dailyRows' => [
                    ['date' => '2025-03-01', 'leads' => 8, 'closing' => 1, 'rate' => 12.5, 'omzet' => 1500000, 'jumlahDress' => 2, 'avgSales' => 750000, 'avgDress' => 2, 'dressRate' => 25],
                ],
                'total' => ['leads' => 8, 'closing' => 1, 'rate' => 12.5, 'omzet' => 1500000, 'jumlahDress' => 2, 'avgSales' => 750000, 'avgDress' => 2, 'dressRate' => 25],
            ],
        ];
    }

    public function test_flow_leads_lengkap_kirim_html_gabungan_dua_cs(): void
    {
        $telegram = $this->fakeTelegram();
        $telegram->shouldNotReceive('sendMessage');
        $telegram->shouldReceive('sendDocument')
            ->once()
            ->withArgs(function ($chatId, $filename, $html) {
                return $chatId === 555
                    && $filename === 'Laporan Leads.html'
                    && str_contains($html, '<!DOCTYPE html>')
                    && str_contains($html, 'Siti')
                    && str_contains($html, 'Rudi')
                    && str_contains($html, 'LAPORAN SUMMARY')
                    && str_contains($html, 'Ringkasan harian Siti oke.')
                    && str_contains($html, 'Ringkasan periode Siti mantap.')
                    && str_contains($html, 'Ringkasan harian Rudi oke.')
                    && str_contains($html, 'Ringkasan periode Rudi mantap.');
            });

        $capturedPrompts = [];
        $capturedPayloads = [];

        $ai = Mockery::mock(ReportAi::class);
        $ai->shouldReceive('readFile')
            ->once()
            ->with('%PDF-FAKE-BYTES%', 'application/pdf', Mockery::type('string'))
            ->andReturn($this->twoCsJson());
        $ai->shouldReceive('analyze')
            ->twice()
            ->andReturnUsing(function (string $systemPrompt, array $payload) use (&$capturedPrompts, &$capturedPayloads) {
                $capturedPrompts[] = $systemPrompt;
                $capturedPayloads[] = $payload;

                $name = $payload['csName'];

                return "📊 *LAPORAN HARIAN*\nRingkasan harian {$name} oke.\n\n📊 *LAPORAN SUMMARY*\nRingkasan periode {$name} mantap.";
            });
        $this->app->instance(ReportAi::class, $ai);

        app(LeadsReportFlow::class)->handle(555, $this->document());

        // System prompt yang benar2 dikirim ke AI harus memuat business rule verbatim.
        $this->assertNotEmpty($capturedPrompts);
        foreach ($capturedPrompts as $prompt) {
            $this->assertStringContainsString('Rp 2.800.000', $prompt);
            $this->assertStringContainsString('Bobby, Alfin, Surya, Danu', $prompt);
            $this->assertStringContainsString('Rp 3.500.000', $prompt);
            $this->assertStringContainsString('Rp 85.000.000', $prompt);
            $this->assertStringContainsString('Rp 100.000.000', $prompt);
            $this->assertStringContainsString('25%', $prompt);
        }

        // Agregasi PHP (port "Code in parse"): dailyRows Siti diurutkan dulu, h1 = tanggal
        // TERAKHIR setelah sort (2025-03-02), bukan elemen terakhir di array mentah.
        $siti = collect($capturedPayloads)->firstWhere('csName', 'Siti');
        $this->assertSame('2025-03-02', $siti['h1']['date']);
        $this->assertSame(10, $siti['h1']['leads']);
        $this->assertSame('2025-03-01', $siti['period']['from']);
        $this->assertSame('2025-03-02', $siti['period']['to']);
        // period ambil dari total apa adanya (bukan dihitung ulang dari dailyRows).
        $this->assertSame(4800000, $siti['period']['omzet']);
        $this->assertSame(22, $siti['period']['leads']);
    }

    /**
     * FINAL REVIEW Finding 2: pesan ke user harus GENERIK — TIDAK boleh
     * memuat teks exception asli ('model API key kosong'). Assert exact match
     * (bukan cuma str_contains prefix) supaya kebocoran teks apa pun sesudah
     * prefix otomatis ketahuan gagal.
     */
    public function test_ai_readfile_lempar_aiexception_kirim_pesan_generik_tanpa_kirim_dokumen(): void
    {
        $telegram = $this->fakeTelegram();
        $telegram->shouldNotReceive('sendDocument');
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->with(555, 'Maaf, gagal memproses laporan. Coba lagi, atau hubungi admin.');

        $ai = Mockery::mock(ReportAi::class);
        $ai->shouldReceive('readFile')->once()->andThrow(new AiException('model API key kosong'));
        $ai->shouldNotReceive('analyze');
        $this->app->instance(ReportAi::class, $ai);

        app(LeadsReportFlow::class)->handle(555, $this->document());
    }

    /** FINAL REVIEW Finding 2: sama seperti di atas, utk exception dari analyze() ('rate limit tercapai'). */
    public function test_ai_analyze_lempar_exception_kirim_pesan_generik_tanpa_kirim_dokumen(): void
    {
        $telegram = $this->fakeTelegram();
        $telegram->shouldNotReceive('sendDocument');
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->with(555, 'Maaf, gagal memproses laporan. Coba lagi, atau hubungi admin.');

        $ai = Mockery::mock(ReportAi::class);
        $ai->shouldReceive('readFile')->once()->andReturn($this->twoCsJson());
        $ai->shouldReceive('analyze')->once()->andThrow(new AiException('rate limit tercapai'));
        $this->app->instance(ReportAi::class, $ai);

        app(LeadsReportFlow::class)->handle(555, $this->document());
    }

    /**
     * FINAL REVIEW Finding 3: getFile()/downloadFile() dulu di LUAR try/catch
     * flow (gagal unduh = user diam tanpa balasan) — sekarang ikut dibungkus.
     * Gagal PALING AWAL (getFile) sebelum readFile/analyze sempat dipanggil.
     */
    public function test_unduh_file_gagal_kirim_pesan_generik_tanpa_kirim_dokumen(): void
    {
        $telegram = Mockery::mock(TelegramClient::class);
        $telegram->shouldReceive('getFile')->once()->with('FILE123')
            ->andThrow(new RuntimeException('Telegram getFile gagal (500): boom'));
        $telegram->shouldNotReceive('downloadFile');
        $telegram->shouldNotReceive('sendDocument');
        $telegram->shouldReceive('sendMessage')->once()
            ->with(555, 'Maaf, gagal memproses laporan. Coba lagi, atau hubungi admin.');
        $this->app->instance(TelegramClient::class, $telegram);

        $ai = Mockery::mock(ReportAi::class);
        $ai->shouldNotReceive('readFile');
        $ai->shouldNotReceive('analyze');
        $this->app->instance(ReportAi::class, $ai);

        app(LeadsReportFlow::class)->handle(555, $this->document());
    }

    public function test_readfile_kembalikan_array_kosong_kirim_pesan_ramah_tanpa_kirim_dokumen(): void
    {
        $telegram = $this->fakeTelegram();
        $telegram->shouldNotReceive('sendDocument');
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn ($chatId, $text) => $chatId === 555 && str_contains($text, 'Maaf, gagal memproses laporan'));

        $ai = Mockery::mock(ReportAi::class);
        $ai->shouldReceive('readFile')->once()->andReturn([]);
        $ai->shouldNotReceive('analyze');
        $this->app->instance(ReportAi::class, $ai);

        app(LeadsReportFlow::class)->handle(555, $this->document());
    }
}
