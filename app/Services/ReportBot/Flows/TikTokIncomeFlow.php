<?php

namespace App\Services\ReportBot\Flows;

use App\Models\TelegramBotPendingFile;
use App\Services\ReportBot\TelegramClient;
use App\Services\ReportBot\TikTokIncomeN8nService;
use App\Support\SpreadsheetReader;
use App\Support\XlsxWriter;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Task 12: flow TikTok Income — REPLIKASI n8n "Cache Order CSV" + "Code Parse
 * income" (Task 11: TikTokIncomeN8nService), TANPA AI sama sekali. Beda dari
 * Leads/Ads (satu file per laporan): flow ini butuh DUA file yang datang di
 * DUA webhook call TERPISAH (CSV Order dulu, xlsx Income belakangan) — state
 * ("chat mana sudah kirim csv" + isi filenya) HARUS bertahan lintas request:
 *   - TIDAK bisa pakai tempnam()/sys_get_temp_dir() request-scoped ala
 *     AdsReportFlow::withTempFile() (dihapus/berisiko disapu OS sebelum
 *     webhook kedua datang).
 *   - Path file disimpan permanen di storage_path('app/report-bot/...'),
 *     nama file DETERMINISTIK per chat+kind ("{chatId}-orders.csv" /
 *     "{chatId}-income.xlsx") — upload CSV baru dari chat yang sama otomatis
 *     menimpa file lama (bukan menumpuk file yatim di disk).
 *   - "Siapa sudah kirim csv" dicatat lewat baris App\Models\TelegramBotPendingFile
 *     (Task 3, migrasi 000073, kind='csv') — dicari ulang saat webhook KEDUA
 *     (xlsx Income) datang, lalu dihapus setelah laporan terkirim.
 *
 * Alur:
 *   1. Unduh bytes (getFile()['result']['file_path'] -> downloadFile()) —
 *      envelope PERSIS TelegramClient/LeadsReportFlow/AdsReportFlow.
 *   2. Cabang per tipe file (ekstensi file_name, fallback mime_type):
 *      - .csv (Order "Semua pesanan"): tulis bytes ke path permanen -> upsert
 *        TelegramBotPendingFile (chat_id, kind=csv, path) -> sendMessage minta
 *        file Income xlsx.
 *      - .xlsx (Income): cari pending csv chat ini. Tak ada -> sendMessage
 *        minta csv dulu (sendDocument TIDAK dipanggil, TIDAK menyentuh disk).
 *        Ada -> tulis bytes xlsx ke path permanen -> SpreadsheetReader::rows()
 *        (grid numeric-indexed, baris0=header) -> gridToAssocRows() (pemetaan
 *        yang Task 11 SENGAJA tidak sediakan — lihat dokblok kelas
 *        TikTokIncomeN8nService, "Task 12 ... petakan baris grid jadi
 *        header=>value PERSIS item.json n8n") -> TikTokIncomeN8nService::build()
 *        digabung TikTokIncomeN8nService::parseOrderCsv() (baca file csv
 *        pending) -> XlsxWriter::write() -> baca bytes dari path hasilnya ->
 *        TelegramClient::sendDocument() -> BERSIHKAN (hapus baris pending +
 *        KEDUA file permanen: csv & xlsx).
 *   3. TANPA AI. Satu try/catch TUNGGAL membungkus SELURUH langkah di atas
 *      (unduh s/d kirim dokumen) — gagal di titik manapun -> Log::error()
 *      detail lengkap SERVER-SIDE + sendMessage pesan GENERIK ke user (FINAL
 *      REVIEW Finding 2: sebelumnya $e->getMessage() mentah ikut terkirim ke
 *      chat — bisa memuat data sensitif, mis. token lewat TelegramClient::
 *      send()), webhook TIDAK boleh throw ke pemanggil.
 *
 * CATATAN API XlsxWriter::write(): brief tugas awal mengira method ini
 * balikin BYTES langsung. Setelah dicek sumbernya: ia balikin PATH file .xlsx
 * sementara (tempnam(), TIDAK dihapus otomatis olehnya — beda dari
 * download() yang streaming+hapus via deleteFileAfterSend). Di sini bytes-nya
 * diambil lewat file_get_contents($path), lalu path sementara itu di-@unlink()
 * sendiri — "tulis temp lalu baca bytes", opsi yang brief sendiri sudah
 * antisipasi ("cek API; kalau cuma download(), tambah toString() atau tulis
 * temp lalu baca bytes"). Tidak menambah method baru ke XlsxWriter.
 *
 * Pesan ke user SENGAJA teks POLOS (tanpa *markdown*) — TelegramClient::sendMessage()
 * tidak mengirim parse_mode ke Telegram, jadi tanda bintang bakal tampil
 * literal ke user (persis catatan minor Task 8 di progress.md; tidak diulang
 * di sini).
 */
class TikTokIncomeFlow
{
    /**
     * Pesan generik utk SEMUA kegagalan tak terduga di handle() — SENGAJA
     * tidak menyertakan $e->getMessage() (FINAL REVIEW Finding 2): detail
     * asli exception bisa memuat info sensitif (mis. token Telegram lewat
     * ConnectionException, lihat TelegramClient::send()) atau sekadar
     * membingungkan user non-teknis. Detail lengkap tetap tercatat via
     * Log::error() di titik tangkap (lihat catch di handle()).
     */
    private const ERROR_GENERIC = 'Maaf, gagal memproses laporan. Coba lagi, atau hubungi admin.';

    private const MSG_NEED_CSV_FIRST = 'Kirim Order CSV ("Semua pesanan") dulu, baru file Income .xlsx.';

    private const MSG_UNKNOWN_TYPE = 'File tidak dikenali — kirim Order CSV ("Semua pesanan") atau file Income .xlsx.';

    private const STORAGE_SUBDIR = 'app/report-bot';

    public function __construct(private TelegramClient $telegram) {}

    /**
     * @param  array<string,mixed>  $document  message.document dari update Telegram (file_id/file_name/mime_type).
     */
    public function handle(int|string $chatId, array $document): void
    {
        try {
            $fileId = (string) ($document['file_id'] ?? '');
            $filePath = (string) ($this->telegram->getFile($fileId)['result']['file_path'] ?? '');
            $bytes = $this->telegram->downloadFile($filePath);

            match ($this->detectExtension($document)) {
                'csv' => $this->handleOrderCsv($chatId, $bytes),
                'xlsx' => $this->handleIncomeXlsx($chatId, $bytes),
                default => $this->telegram->sendMessage($chatId, self::MSG_UNKNOWN_TYPE),
            };
        } catch (Throwable $e) {
            Log::error('report-bot tiktok-income gagal', ['e' => $e->getMessage()]);
            $this->telegram->sendMessage($chatId, self::ERROR_GENERIC);
        }
    }

    /** Ekstensi file_name (lower-case); fallback ke mime_type kalau ekstensinya bukan csv/xlsx. */
    private function detectExtension(array $document): string
    {
        $ext = strtolower(pathinfo((string) ($document['file_name'] ?? ''), PATHINFO_EXTENSION));
        if (in_array($ext, ['csv', 'xlsx'], true)) {
            return $ext;
        }

        $mime = strtolower((string) ($document['mime_type'] ?? ''));
        if (str_contains($mime, 'csv')) {
            return 'csv';
        }
        if (str_contains($mime, 'spreadsheet') || str_contains($mime, 'excel')) {
            return 'xlsx';
        }

        return $ext;
    }

    private function handleOrderCsv(int|string $chatId, string $bytes): void
    {
        $path = $this->persistentPath($chatId, 'orders.csv');
        file_put_contents($path, $bytes);

        TelegramBotPendingFile::updateOrCreate(
            ['chat_id' => (string) $chatId, 'kind' => 'csv'],
            ['path' => $path],
        );

        $summary = TikTokIncomeN8nService::orderCsvSummary($bytes);
        $this->telegram->sendMessage($chatId, self::csvReceivedMessage($summary));
    }

    /**
     * Pesan konfirmasi CSV — VERBATIM node n8n "Cache Order CSV" (baris ~369-377
     * sumber): baris terbaca + order unik + status SKU dikenali / daftar (maks 5)
     * SKU belum dikenal. Parity persis n8n biar user tahu kalau ada SKU yang
     * belum kepetakan (order-nya jadi 0 di laporan) — bukan sekadar "diterima".
     *
     * @param  array{lineCount:int, orders:int, unmapped:array<int,string>}  $summary
     */
    private static function csvReceivedMessage(array $summary): string
    {
        $unmapped = $summary['unmapped'];
        $skuLine = $unmapped === []
            ? '• Semua SKU dikenali 👍'
            : '⚠️ SKU belum dikenal: '.count($unmapped)."\n"
                .implode("\n", array_map(static fn (string $s): string => '   - '.$s, array_slice($unmapped, 0, 5)));

        return "✅ Data order tersimpan.\n"
            .'• Baris CSV terbaca: '.$summary['lineCount']."\n"
            .'• Order unik: '.$summary['orders']."\n"
            .$skuLine
            ."\n\nSekarang kirim file income (.xlsx) untuk digabung.";
    }

    private function handleIncomeXlsx(int|string $chatId, string $bytes): void
    {
        $pending = TelegramBotPendingFile::where('chat_id', (string) $chatId)
            ->where('kind', 'csv')
            ->first();

        if (! $pending) {
            $this->telegram->sendMessage($chatId, self::MSG_NEED_CSV_FIRST);

            return;
        }

        $xlsxPath = $this->persistentPath($chatId, 'income.xlsx');
        file_put_contents($xlsxPath, $bytes);

        $incomeRows = $this->gridToAssocRows(SpreadsheetReader::rows($xlsxPath, 'xlsx'));
        $orderIndex = TikTokIncomeN8nService::parseOrderCsv((string) file_get_contents($pending->path));
        $sheets = TikTokIncomeN8nService::build($incomeRows, $orderIndex);

        $tmpReportPath = XlsxWriter::write($sheets);
        $reportBytes = (string) file_get_contents($tmpReportPath);
        @unlink($tmpReportPath);

        $this->telegram->sendDocument($chatId, 'Laporan Income TikTok.xlsx', $reportBytes);

        // Laporan sudah terkirim — bersihkan baris pending + KEDUA file permanen
        // (beda dari $tmpReportPath di atas yang memang cuma buffer sementara).
        @unlink($pending->path);
        @unlink($xlsxPath);
        $pending->delete();
    }

    /**
     * Port pemetaan yang Task 11 SENGAJA tidak sediakan (lihat dokblok kelas
     * TikTokIncomeN8nService, "Task 12 ... petakan baris grid jadi
     * header=>value"): SpreadsheetReader::rows() balikin grid numeric-indexed
     * (baris0=header) — TikTokIncomeN8nService::build() butuh array asosiatif
     * header=>value per baris (PERSIS bentuk item.json n8n) supaya pick()-nya
     * bisa mencocokkan nama kolom. Grid kosong (xlsx tanpa baris sama sekali)
     * -> [] (konsisten dgn build([], [])).
     *
     * @param  array<int, array<int, string>>  $grid
     * @return array<int, array<string, string>>
     */
    private function gridToAssocRows(array $grid): array
    {
        if ($grid === []) {
            return [];
        }

        $headers = array_shift($grid);
        $rows = [];
        foreach ($grid as $row) {
            $assoc = [];
            foreach ($headers as $i => $header) {
                $assoc[$header] = $row[$i] ?? '';
            }
            $rows[] = $assoc;
        }

        return $rows;
    }

    /** Path permanen deterministik per chat+kind — upload ulang dari chat yang sama otomatis menimpa (bukan menumpuk file). */
    private function persistentPath(int|string $chatId, string $suffix): string
    {
        $dir = storage_path(self::STORAGE_SUBDIR);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir.DIRECTORY_SEPARATOR.$chatId.'-'.$suffix;
    }
}
