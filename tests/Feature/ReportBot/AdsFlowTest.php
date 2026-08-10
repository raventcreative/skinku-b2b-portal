<?php

namespace Tests\Feature\ReportBot;

use App\Services\Ai\AiException;
use App\Services\ReportBot\Flows\AdsReportFlow;
use App\Services\ReportBot\ReportAi;
use App\Services\ReportBot\TelegramClient;
use App\Support\XlsxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Task 10: AdsReportFlow — unduh file (TelegramClient) -> BERCABANG per tipe:
 *   .xlsx (jalur UTAMA, tanpa AI vision) -> SpreadsheetReader -> susun JSON
 *   {periodData,summary,lastDay} langsung di PHP;
 *   .pdf (jalur CADANGAN) -> PdfTextExtractor -> looksUnreadable? ya ->
 *   ReportAi::readFile (multimodal) : tidak -> parse manual (port node n8n
 *   "Code Parse & Split Ads") ke bentuk JSON yang sama
 * -> ReportAi::analyze (system prompt verbatim "AI Daily ADS Analyzer") ->
 * pecah daily/summary (port token-split "Code in Generate HTML1") -> render
 * HTML -> TelegramClient::sendDocument. ReportAi & TelegramClient di-mock
 * lewat container binding — tidak ada panggilan API asli sama sekali.
 */
class AdsFlowTest extends TestCase
{
    use RefreshDatabase;

    private function xlsxDocument(): array
    {
        return [
            'file_id' => 'FILE_XLSX',
            'file_name' => 'Report Ads Skinku.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }

    private function pdfDocument(): array
    {
        return [
            'file_id' => 'FILE_PDF',
            'file_name' => 'Report Ads Skinku.pdf',
            'mime_type' => 'application/pdf',
        ];
    }

    /** Bind mock TelegramClient dengan getFile/downloadFile sudah dikanibalisasi utk 1 fileId/path/bytes tertentu. */
    private function fakeTelegram(string $fileId, string $filePath, string $bytes): MockInterface
    {
        $telegram = Mockery::mock(TelegramClient::class);
        $telegram->shouldReceive('getFile')
            ->once()
            ->with($fileId)
            ->andReturn(['ok' => true, 'result' => ['file_path' => $filePath]]);
        $telegram->shouldReceive('downloadFile')
            ->once()
            ->with($filePath)
            ->andReturn($bytes);

        $this->app->instance(TelegramClient::class, $telegram);

        return $telegram;
    }

    /** Bangun fixture .xlsx (2 baris harian + 1 baris Total) lewat XlsxWriter, balikin BYTES-nya. */
    private function xlsxBytes(): string
    {
        $path = XlsxWriter::write([
            'Ads' => [
                'headers' => ['Day', 'Views', 'Clicks', 'CTR', 'Link Clicks', 'Spend', 'Reach', 'Reach Trend', 'Jabar', 'Jatim', 'Lain'],
                'rows' => [
                    // "Lain" hari 1 sengaja "-" (bukan angka) -> membuktikan nullableCell()
                    // memetakannya ke null, bukan 0 (konvensi "-" = sel kosong, sama dgn Leads).
                    [1, 500, 300, '60%', 200, 100000, 450, 10, 40000, 35000, '-'],
                    [2, 520, 340, '65,4%', 220, 150000, 480, 12, 50000, 30000, 20000],
                    ['Total', 1020, 640, '62,7%', 420, 250000, 930],
                ],
            ],
        ]);
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /** xlsx cuma header, tanpa baris data — utk tes guard "tidak ada data". */
    private function xlsxBytesHeaderOnly(): string
    {
        $path = XlsxWriter::write([
            'Ads' => [
                'headers' => ['Day', 'Views', 'Clicks', 'CTR', 'Link Clicks', 'Spend', 'Reach', 'Reach Trend', 'Jabar', 'Jatim', 'Lain'],
                'rows' => [],
            ],
        ]);
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /**
     * PDF sintetis (bukan lewat XlsxWriter) berisi content stream mentah yang
     * bisa dibaca PdfTextExtractor::extract() jadi teks BERSIH/terbaca — teknik
     * sama persis dengan PdfTextExtractorTest::test_tj_array_kosong_tidak_crash
     * (tempnam + gzcompress + "stream\n...\nendstream", tanpa struktur PDF
     * lengkap — extract() cuma nyari pola stream/endstream di mana pun).
     *
     * Token urutan: Day1 blok inti 8 (day,views,clicks,ctr%,linkClicks,spend,
     * reach,reachTrend) + 3 regional (jabar,jatim,lain) = 11 token, LALU baris
     * "Total" + 6 angka (views,clicks,ctr%,linkClicks,spend,reach). Regional
     * day1 SENGAJA diisi (bukan cuma 8 token inti) supaya cursor loop utama
     * pas mendarat di awal blok Total (11 token dgn cukup token disisakan),
     * bukan salah "menelan" 3 angka pertama Total sbg regional day1 (itu
     * quirk asli node "Code Parse & Split Ads" saat baris Total < 8 token
     * langsung nempel setelah baris harian TANPA regional).
     */
    private function readablePdfBytes(): string
    {
        $tjLines = ['1', '520', '340', '65,4%', '220', '150000', '480', '12', '50000', '30000', '20000',
            'Total', '520', '340', '65,4%', '220', '150000', '480'];
        $content = implode("\n", array_map(fn ($t) => "($t) Tj", $tjLines))."\n";

        $path = tempnam(sys_get_temp_dir(), 'ads_pdf_readable_');
        file_put_contents($path, "stream\n".gzcompress($content)."\nendstream");
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private function realCidPdfBytes(): string
    {
        return (string) file_get_contents(base_path('tests/fixtures/report_bot/ads_cid.pdf'));
    }

    private function narratedText(): string
    {
        return "📅 *Laporan Iklan Harian Skinku*\nAnalisis harian di sini.\n\n"
            .'📊 **Ringkasan Periode**'
            ."\nRingkasan periode di sini.";
    }

    public function test_flow_ads_xlsx_kirim_html_tanpa_ai_vision(): void
    {
        $telegram = $this->fakeTelegram('FILE_XLSX', 'documents/ads.xlsx', $this->xlsxBytes());
        $telegram->shouldNotReceive('sendMessage');
        $telegram->shouldReceive('sendDocument')
            ->once()
            ->withArgs(function ($chatId, $filename, $html) {
                return $chatId === 777
                    && $filename === 'Laporan Ads.html'
                    && str_contains($html, '<!DOCTYPE html>')
                    && str_contains($html, 'Analisis harian di sini.')
                    && str_contains($html, 'Ringkasan periode di sini.');
            });

        $capturedPrompt = null;
        $capturedJson = null;

        $ai = Mockery::mock(ReportAi::class);
        $ai->shouldNotReceive('readFile');
        $ai->shouldReceive('analyze')
            ->once()
            ->andReturnUsing(function (string $systemPrompt, array $json) use (&$capturedPrompt, &$capturedJson) {
                $capturedPrompt = $systemPrompt;
                $capturedJson = $json;

                return $this->narratedText();
            });
        $this->app->instance(ReportAi::class, $ai);

        app(AdsReportFlow::class)->handle(777, $this->xlsxDocument());

        // System prompt yang benar2 dikirim ke AI harus memuat isi verbatim node "AI Daily ADS Analyzer".
        $this->assertNotNull($capturedPrompt);
        $this->assertStringContainsString('analis digital marketing', $capturedPrompt);
        $this->assertStringContainsString('CPC = spend / linkClicks', $capturedPrompt);
        $this->assertStringContainsString('Jabar', $capturedPrompt);
        $this->assertStringContainsString('periodData', $capturedPrompt);

        // Susunan JSON (langsung dari PHP, tanpa AI) dari 2 baris harian + 1 baris Total.
        $this->assertNotNull($capturedJson);
        $this->assertCount(2, $capturedJson['periodData']);
        $this->assertSame(['day' => 1, 'views' => 500, 'clicks' => 300, 'ctr' => 60.0, 'linkClicks' => 200, 'spend' => 100000, 'reach' => 450, 'reachTrend' => 10, 'jabar' => 40000, 'jatim' => 35000, 'lain' => null], $capturedJson['periodData'][0]);
        $this->assertSame(['day' => 2, 'views' => 520, 'clicks' => 340, 'ctr' => 65.4, 'linkClicks' => 220, 'spend' => 150000, 'reach' => 480, 'reachTrend' => 12, 'jabar' => 50000, 'jatim' => 30000, 'lain' => 20000], $capturedJson['periodData'][1]);
        $this->assertSame(['views' => 1020, 'clicks' => 640, 'ctr' => 62.7, 'linkClicks' => 420, 'spend' => 250000, 'reach' => 930], $capturedJson['summary']);
        $this->assertSame(2, $capturedJson['lastDay']);
    }

    public function test_flow_ads_pdf_tak_terbaca_fallback_ai_readfile(): void
    {
        $telegram = $this->fakeTelegram('FILE_PDF', 'documents/ads.pdf', $this->realCidPdfBytes());
        $telegram->shouldNotReceive('sendMessage');
        $telegram->shouldReceive('sendDocument')
            ->once()
            ->withArgs(fn ($chatId, $filename, $html) => $chatId === 777
                && $filename === 'Laporan Ads.html'
                && str_contains($html, 'Analisis harian di sini.')
                && str_contains($html, 'Ringkasan periode di sini.'));

        $canned = ['periodData' => [['day' => 1, 'views' => 500, 'clicks' => 300, 'ctr' => 60, 'linkClicks' => 200, 'spend' => 100000, 'reach' => 450, 'reachTrend' => 5, 'jabar' => null, 'jatim' => null, 'lain' => null]], 'summary' => null, 'lastDay' => 1];

        $ai = Mockery::mock(ReportAi::class);
        $ai->shouldReceive('readFile')
            ->once()
            ->with(Mockery::type('string'), 'application/pdf', Mockery::type('string'))
            ->andReturn($canned);
        $ai->shouldReceive('analyze')
            ->once()
            ->with(Mockery::type('string'), $canned)
            ->andReturn($this->narratedText());
        $this->app->instance(ReportAi::class, $ai);

        app(AdsReportFlow::class)->handle(777, $this->pdfDocument());
    }

    public function test_flow_ads_pdf_terbaca_parse_manual_tanpa_ai_readfile(): void
    {
        $telegram = $this->fakeTelegram('FILE_PDF', 'documents/ads.pdf', $this->readablePdfBytes());
        $telegram->shouldNotReceive('sendMessage');
        $telegram->shouldReceive('sendDocument')->once();

        $capturedJson = null;

        $ai = Mockery::mock(ReportAi::class);
        $ai->shouldNotReceive('readFile');
        $ai->shouldReceive('analyze')
            ->once()
            ->andReturnUsing(function (string $systemPrompt, array $json) use (&$capturedJson) {
                $capturedJson = $json;

                return $this->narratedText();
            });
        $this->app->instance(ReportAi::class, $ai);

        app(AdsReportFlow::class)->handle(777, $this->pdfDocument());

        $this->assertNotNull($capturedJson);
        $this->assertCount(1, $capturedJson['periodData']);
        $this->assertSame(['day' => 1, 'views' => 520, 'clicks' => 340, 'ctr' => 65.4, 'linkClicks' => 220, 'spend' => 150000, 'reach' => 480, 'reachTrend' => 12, 'jabar' => 50000, 'jatim' => 30000, 'lain' => 20000], $capturedJson['periodData'][0]);
        $this->assertSame(['views' => 520, 'clicks' => 340, 'ctr' => 65.4, 'linkClicks' => 220, 'spend' => 150000, 'reach' => 480], $capturedJson['summary']);
        $this->assertSame(1, $capturedJson['lastDay']);
    }

    public function test_ai_analyze_lempar_exception_kirim_pesan_ramah_tanpa_kirim_dokumen(): void
    {
        $telegram = $this->fakeTelegram('FILE_XLSX', 'documents/ads.xlsx', $this->xlsxBytes());
        $telegram->shouldNotReceive('sendDocument');
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn ($chatId, $text) => $chatId === 777 && str_contains($text, 'Maaf, gagal memproses laporan'));

        $ai = Mockery::mock(ReportAi::class);
        $ai->shouldNotReceive('readFile');
        $ai->shouldReceive('analyze')->once()->andThrow(new AiException('rate limit tercapai'));
        $this->app->instance(ReportAi::class, $ai);

        app(AdsReportFlow::class)->handle(777, $this->xlsxDocument());
    }

    public function test_ai_readfile_lempar_exception_kirim_pesan_ramah_tanpa_kirim_dokumen(): void
    {
        $telegram = $this->fakeTelegram('FILE_PDF', 'documents/ads.pdf', $this->realCidPdfBytes());
        $telegram->shouldNotReceive('sendDocument');
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn ($chatId, $text) => $chatId === 777 && str_contains($text, 'Maaf, gagal memproses laporan'));

        $ai = Mockery::mock(ReportAi::class);
        $ai->shouldReceive('readFile')->once()->andThrow(new AiException('model API key kosong'));
        $ai->shouldNotReceive('analyze');
        $this->app->instance(ReportAi::class, $ai);

        app(AdsReportFlow::class)->handle(777, $this->pdfDocument());
    }

    public function test_xlsx_tanpa_baris_data_kirim_pesan_ramah_tanpa_kirim_dokumen(): void
    {
        $telegram = $this->fakeTelegram('FILE_XLSX', 'documents/ads.xlsx', $this->xlsxBytesHeaderOnly());
        $telegram->shouldNotReceive('sendDocument');
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn ($chatId, $text) => $chatId === 777 && str_contains($text, 'Maaf, gagal memproses laporan'));

        $ai = Mockery::mock(ReportAi::class);
        $ai->shouldNotReceive('readFile');
        $ai->shouldNotReceive('analyze');
        $this->app->instance(ReportAi::class, $ai);

        app(AdsReportFlow::class)->handle(777, $this->xlsxDocument());
    }
}
