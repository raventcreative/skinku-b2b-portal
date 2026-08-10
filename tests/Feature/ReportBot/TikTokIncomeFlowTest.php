<?php

namespace Tests\Feature\ReportBot;

use App\Models\TelegramBotPendingFile;
use App\Services\ReportBot\Flows\TikTokIncomeFlow;
use App\Services\ReportBot\TelegramClient;
use App\Support\SpreadsheetReader;
use App\Support\XlsxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 12: TikTokIncomeFlow — REPLIKASI n8n "Cache Order CSV" + "Code Parse
 * income" (Task 11: TikTokIncomeN8nService) lewat bot Telegram, TANPA AI.
 * Beda dari Leads/Ads: dua file (Order CSV lalu Income xlsx) datang di DUA
 * webhook call TERPISAH — pairing-nya lewat App\Models\TelegramBotPendingFile
 * (Task 3). TelegramClient di-mock lewat container binding (persis
 * LeadsFlowTest/AdsFlowTest) — tidak ada panggilan API asli sama sekali.
 *
 * Fixture CSV (2 baris) & xlsx Income (2 baris) sengaja kecil tapi memakai
 * SKU_MAP nyata dari TikTokIncomeN8nService (dibaca dari sumbernya, BUKAN
 * dari hasil jalanin kode) supaya breakdown kategori yang diharapkan bisa
 * dihitung tangan:
 *   - ORDER-A: SKU 1734055986286921642 = Body Serum ("Lotion" => 1 unit
 *     fisik per SKU), qty=2 -> Lotion harus jadi 2.
 *   - ORDER-B: SKU 1734056004809426858 = Scrub 3 Pcs ("Scrub" => 3 unit
 *     fisik per SKU), qty=1 -> Scrub harus jadi 3.
 */
class TikTokIncomeFlowTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_ID = 9001;

    private const ORDER_CSV = "\xEF\xBB\xBFOrder ID,Order Status,Product Name,Variation,Buyer Username,SKU ID,Seller SKU,Original Price,SKU Subtotal Before Discount,Quantity\r\n"
        ."ORDER-A,Selesai,Body Serum,Reguler,buyerA,1734055986286921642,SELLER-1,50000,100000,2\n"
        .'ORDER-B,Selesai,Scrub Mangga 3 Pcs,Reguler,buyerB,1734056004809426858,SELLER-2,30000,30000,1';

    protected function tearDown(): void
    {
        $dir = storage_path('app/report-bot');
        if (is_dir($dir)) {
            foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }

        parent::tearDown();
    }

    private function csvDocument(string $fileId = 'CSVFILE1'): array
    {
        return [
            'file_id' => $fileId,
            'file_name' => 'Semua pesanan.csv',
            'mime_type' => 'text/csv',
        ];
    }

    private function xlsxDocument(string $fileId = 'XLSXFILE1'): array
    {
        return [
            'file_id' => $fileId,
            'file_name' => 'income.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }

    /** Bangun fixture .xlsx Income (2 baris, header ID/EN campuran persis konvensi pick() TikTokIncomeN8nService), balikin BYTES-nya. */
    private function incomeXlsxBytes(): string
    {
        $path = XlsxWriter::write([
            'Income' => [
                'headers' => ['ID Pesanan/Penyesuaian', 'Jenis transaksi', 'Waktu pemesanan', 'Total Pendapatan', 'Total Biaya', 'Jumlah penyelesaian pembayaran'],
                'rows' => [
                    ['ORDER-A', 'Pesanan', '2026-08-01 10:00:00', 100000, 5000, 95000],
                    ['ORDER-B', 'Pesanan', '2026-08-01 11:00:00', 30000, 1000, 29000],
                ],
            ],
        ]);
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /** Mock TelegramClient kosong (belum ada expectation getFile/downloadFile) — dipasang oleh tiap test. */
    private function bindTelegram(): MockInterface
    {
        $telegram = Mockery::mock(TelegramClient::class);
        $this->app->instance(TelegramClient::class, $telegram);

        return $telegram;
    }

    /** Baca bytes xlsx (hasil sendDocument) balik jadi grid baris via SpreadsheetReader, tanpa menyentuh disk permanen. */
    private function readXlsxBytes(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'assert_xlsx_');
        file_put_contents($tmp, $bytes);
        $grid = SpreadsheetReader::rows($tmp, 'xlsx');
        @unlink($tmp);

        return $grid;
    }

    public function test_csv_pertama_kirim_pending_dan_minta_file_income(): void
    {
        $telegram = $this->bindTelegram();
        $telegram->shouldReceive('getFile')->once()->with('CSVFILE1')
            ->andReturn(['ok' => true, 'result' => ['file_path' => 'documents/orders.csv']]);
        $telegram->shouldReceive('downloadFile')->once()->with('documents/orders.csv')
            ->andReturn(self::ORDER_CSV);
        $telegram->shouldReceive('sendMessage')->once()
            ->with(self::CHAT_ID, 'Order CSV diterima ✅ — sekarang kirim file Income (.xlsx).');
        $telegram->shouldNotReceive('sendDocument');

        app(TikTokIncomeFlow::class)->handle(self::CHAT_ID, $this->csvDocument());

        $pending = TelegramBotPendingFile::where('chat_id', (string) self::CHAT_ID)->where('kind', 'csv')->first();
        $this->assertNotNull($pending, 'baris pending csv harus tercatat');
        $this->assertFileExists($pending->path);
        $this->assertSame(self::ORDER_CSV, file_get_contents($pending->path));
    }

    public function test_csv_lalu_xlsx_kirim_laporan_income_lengkap_dan_bersihkan_pending(): void
    {
        $telegram = $this->bindTelegram();
        $telegram->shouldReceive('getFile')->once()->with('CSVFILE1')
            ->andReturn(['ok' => true, 'result' => ['file_path' => 'documents/orders.csv']]);
        $telegram->shouldReceive('downloadFile')->once()->with('documents/orders.csv')
            ->andReturn(self::ORDER_CSV);
        $telegram->shouldReceive('getFile')->once()->with('XLSXFILE1')
            ->andReturn(['ok' => true, 'result' => ['file_path' => 'documents/income.xlsx']]);
        $telegram->shouldReceive('downloadFile')->once()->with('documents/income.xlsx')
            ->andReturn($this->incomeXlsxBytes());
        $telegram->shouldReceive('sendMessage')->once(); // balasan "csv diterima" pada webhook pertama

        $capturedBytes = null;
        $telegram->shouldReceive('sendDocument')->once()
            ->withArgs(function ($chatId, $filename, $bytes) use (&$capturedBytes) {
                $capturedBytes = $bytes;

                return $chatId === self::CHAT_ID && $filename === 'Laporan Income TikTok.xlsx';
            });

        $flow = app(TikTokIncomeFlow::class);
        $flow->handle(self::CHAT_ID, $this->csvDocument());

        $pendingBeforeXlsx = TelegramBotPendingFile::where('chat_id', (string) self::CHAT_ID)->where('kind', 'csv')->first();
        $this->assertNotNull($pendingBeforeXlsx);
        $csvPath = $pendingBeforeXlsx->path;

        $flow->handle(self::CHAT_ID, $this->xlsxDocument());

        // sendDocument menerima bytes xlsx NON-KOSONG dan valid (bisa dibaca ulang).
        // Catatan: SpreadsheetReader::rows() trim() SETIAP sel (termasuk header) saat
        // membaca, jadi 'Order/adjustment ID  '/'Type ' (OUTPUT_COLUMNS, trailing-space
        // sengaja) balik jadi 'Order/adjustment ID'/'Type' tanpa spasi di sisi pembaca —
        // bukan bug flow, cuma perilaku SpreadsheetReader; disesuaikan di assertion ini.
        $this->assertNotEmpty($capturedBytes);
        $grid = $this->readXlsxBytes($capturedBytes);
        $headers = $grid[0];
        $this->assertContains('Order/adjustment ID', $headers, 'header OUTPUT_COLUMNS TikTokIncomeN8nService harus ada di xlsx terkirim');

        $rowsByOrder = [];
        foreach (array_slice($grid, 1) as $row) {
            $assoc = array_combine($headers, $row);
            $rowsByOrder[$assoc['Order/adjustment ID']] = $assoc;
        }
        $this->assertSame(['ORDER-A', 'ORDER-B'], array_keys($rowsByOrder), 'kedua order harus muncul, urutan sesuai income xlsx');

        // Breakdown kategori benar (dihitung tangan dari SKU_MAP): ORDER-A =
        // Body Serum qty 2 -> Lotion=2; ORDER-B = Scrub 3 Pcs qty 1 -> Scrub=3.
        $this->assertSame('2', $rowsByOrder['ORDER-A']['Lotion']);
        $this->assertSame('0', $rowsByOrder['ORDER-A']['Scrub']);
        $this->assertSame('3', $rowsByOrder['ORDER-B']['Scrub']);
        $this->assertSame('0', $rowsByOrder['ORDER-B']['Lotion']);
        $this->assertSame('Order', $rowsByOrder['ORDER-A']['Type'], 'translateType("Pesanan") harus jadi "Order"');
        $this->assertSame('100000', $rowsByOrder['ORDER-A']['Total Revenue']);
        $this->assertSame('5000', $rowsByOrder['ORDER-A']['Total Fees']);
        $this->assertSame('95000', $rowsByOrder['ORDER-A']['Total settlement amount']);
        $this->assertSame('IDR', $rowsByOrder['ORDER-A']['Currency'], 'Currency kosong di fixture harus default ke IDR');

        // Bersih-bersih: baris pending TERHAPUS, kedua file permanen (csv & xlsx) TERHAPUS.
        $this->assertNull(TelegramBotPendingFile::where('chat_id', (string) self::CHAT_ID)->where('kind', 'csv')->first());
        $this->assertFileDoesNotExist($csvPath);
        $this->assertFileDoesNotExist(storage_path('app/report-bot/'.self::CHAT_ID.'-income.xlsx'));
    }

    public function test_xlsx_tanpa_pending_csv_minta_csv_dulu(): void
    {
        $telegram = $this->bindTelegram();
        $telegram->shouldReceive('getFile')->once()->with('XLSXFILE1')
            ->andReturn(['ok' => true, 'result' => ['file_path' => 'documents/income.xlsx']]);
        $telegram->shouldReceive('downloadFile')->once()->with('documents/income.xlsx')
            ->andReturn($this->incomeXlsxBytes());
        $telegram->shouldReceive('sendMessage')->once()
            ->with(self::CHAT_ID, 'Kirim Order CSV ("Semua pesanan") dulu, baru file Income .xlsx.');
        $telegram->shouldNotReceive('sendDocument');

        app(TikTokIncomeFlow::class)->handle(self::CHAT_ID, $this->xlsxDocument());

        $this->assertNull(TelegramBotPendingFile::where('chat_id', (string) self::CHAT_ID)->first());
    }

    public function test_tipe_file_tak_dikenal_kirim_pesan_ramah(): void
    {
        $telegram = $this->bindTelegram();
        $telegram->shouldReceive('getFile')->once()->with('WEIRDFILE')
            ->andReturn(['ok' => true, 'result' => ['file_path' => 'documents/report.pdf']]);
        $telegram->shouldReceive('downloadFile')->once()->with('documents/report.pdf')
            ->andReturn('%PDF-NOT-RELEVANT%');
        $telegram->shouldReceive('sendMessage')->once()
            ->withArgs(fn ($chatId, $text) => $chatId === self::CHAT_ID && stripos($text, 'csv') !== false);
        $telegram->shouldNotReceive('sendDocument');

        app(TikTokIncomeFlow::class)->handle(self::CHAT_ID, [
            'file_id' => 'WEIRDFILE',
            'file_name' => 'report.pdf',
            'mime_type' => 'application/pdf',
        ]);
    }

    /**
     * FINAL REVIEW Finding 2: pesan ke user harus GENERIK — TIDAK boleh
     * memuat teks exception asli ('boom'/status 500). Assert exact match
     * (bukan cuma str_contains prefix) supaya kebocoran teks apa pun sesudah
     * prefix otomatis ketahuan gagal.
     */
    public function test_unduh_file_gagal_kirim_pesan_generik_tanpa_efek_samping(): void
    {
        $telegram = $this->bindTelegram();
        $telegram->shouldReceive('getFile')->once()->with('CSVFILE1')
            ->andThrow(new RuntimeException('Telegram getFile gagal (500): boom'));
        $telegram->shouldNotReceive('downloadFile');
        $telegram->shouldReceive('sendMessage')->once()
            ->with(self::CHAT_ID, 'Maaf, gagal memproses laporan. Coba lagi, atau hubungi admin.');
        $telegram->shouldNotReceive('sendDocument');

        app(TikTokIncomeFlow::class)->handle(self::CHAT_ID, $this->csvDocument());

        $this->assertNull(TelegramBotPendingFile::where('chat_id', (string) self::CHAT_ID)->first());
    }
}
