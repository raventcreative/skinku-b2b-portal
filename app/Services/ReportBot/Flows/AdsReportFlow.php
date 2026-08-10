<?php

namespace App\Services\ReportBot\Flows;

use App\Services\ReportBot\ReportAi;
use App\Services\ReportBot\TelegramClient;
use App\Support\PdfTextExtractor;
use App\Support\SpreadsheetReader;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Task 10: flow Ads — laporan performa iklan. Alur: unduh file (TelegramClient)
 * -> BERCABANG per tipe file (ekstensi `file_name`):
 *   - .xlsx (jalur UTAMA, lebih murah — TANPA AI vision): tulis bytes ke file
 *     sementara -> SpreadsheetReader::rows() -> susun LANGSUNG di PHP ke bentuk
 *     JSON {periodData[],summary,lastDay} (pemetaan header sendiri, lihat
 *     fromXlsxRows() — TIDAK ada di sumber n8n, karena Ads di n8n cuma PDF;
 *     ini desain baru utk jalur xlsx yang lebih murah).
 *   - .pdf (jalur CADANGAN, method aslinya di n8n): tulis bytes ke file
 *     sementara -> PdfTextExtractor::extract() -> bila looksUnreadable() (lazim
 *     utk PDF Ads ASLI ber-font CID, lihat tests/fixtures/report_bot/ads_cid.pdf)
 *     -> ReportAi::readFile (multimodal); kalau ternyata teksnya terbaca bersih
 *     -> parse manual, PORT dari node n8n "Code Parse & Split Ads" (fromAdsText()).
 * -> KEDUA cabang menghasilkan bentuk JSON yang SAMA, lalu deriveBrand() menyisipkan
 * field "brand" (lihat FIX ROUND 1 di bawah) -> ReportAi::analyze (system prompt
 * VERBATIM node "AI Daily ADS Analyzer", lihat catatan di ADS_SYSTEM_PROMPT) ->
 * satu teks naratif -> pecah daily/summary (PORT token-split node "Code in
 * Generate HTML1", lihat splitReport()) -> render satu halaman HTML
 * (resources/views/report_bot/ads.blade.php, struktur ala leads.blade.php) ->
 * TelegramClient::sendDocument.
 *
 * Sengaja TIDAK diport dari "Code in Generate HTML1": auto-detect brand dari
 * teks balasan AI (regex atas frasa "Laporan Iklan Harian ...") & deteksi
 * rentang Day pertama/terakhir dari teks balasan AI utk subjudul halaman.
 * Keduanya murni kosmetik judul (bukan isi laporan), TIDAK disebut di alur
 * bernomor task ini ("mirror leads.blade.php structure"), dan leads.blade.php
 * sendiri — struktur yang eksplisit diminta ditiru — juga tidak melakukan hal
 * serupa (judulnya statis apa pun isi laporannya).
 *
 * FIX ROUND 1 (review, brand ungrounded): system prompt hasil porting verbatim
 * awal MEMUAT 3 baris ekspresi n8n yang SUDAH MATI di arsitektur PHP ini —
 * "Brand: {{ $('Switch')...file_name... }}" (2x) & "{{ $json }}" — n8n
 * me-resolve ekspresi itu SEBELUM system message sampai ke AI; PHP tidak, jadi
 * teks ekspresi mentah itu terkirim apa adanya ke AI ("{{ $json }}" sendiri tak
 * masalah — datanya tetap sampai lewat argumen $json ReportAi::analyze() yang
 * terpisah — tapi "Brand: ..." fatal: OUTPUT FORMAT yang WAJIB,
 * "📅 **Laporan Iklan Harian {{brand}}**", jadi tak pernah ter-ground, AI akan
 * echo literal "{{brand}}" atau mengarang nama brand di judul TIAP laporan).
 * Diperbaiki dgn deriveBrand() (murni PHP, dari $document['file_name'] — BUKAN
 * first-word crude ala n8n) yang hasilnya disisipkan ke $json['brand'] SEBELUM
 * analyze() (lihat handle()); prompt disunting MINIMAL — 3 baris ekspresi mati
 * dibuang & diganti SATU instruksi eksplisit "pakai field brand dari input
 * JSON", PERSIS konvensi "{{lastDay}}" yang sudah lebih dulu valid krn lastDay
 * memang field JSON asli. SISANYA (business rule, rumus CPC/CPM, seluruh
 * OUTPUT FORMAT termasuk token "{{brand}}"/"{{lastDay}}" itu sendiri) tetap
 * byte-identik ke sumber n8n — lihat catatan & verifikasi di ADS_SYSTEM_PROMPT.
 *
 * ReportAi TIDAK menangkap App\Services\Ai\AiException sendiri (lihat ReportAi
 * & LeadsReportFlow) — try/catch di sini membungkus (a) unduh (getFile/
 * downloadFile) + langkah "susun JSON" (mencakup titik panggilan AI readFile
 * PLUS SpreadsheetReader/PdfTextExtractor yg sepaket dgnnya — gagal file
 * rusak/tak terbaca pun pantas dapat pesan ramah yg sama, bukan cuma
 * AiException) dan (b) analyze() + render HTML + sendDocument (FINAL REVIEW
 * Finding 3: unduh & sendDocument dulu di LUAR try/catch, sekarang ikut
 * dibungkus). Gagal di titik manapun -> pesan GENERIK ke user via sendMessage
 * (FINAL REVIEW Finding 2: detail exception asli — bisa memuat data sensitif,
 * mis. token via TelegramClient::send() — di-Log::error() SERVER-SIDE saja,
 * TIDAK PERNAH diteruskan ke user), TIDAK ada sendDocument kalau gagal.
 */
class AdsReportFlow
{
    /** Prefix pesan VALIDASI (bukan exception) — dipakai apa adanya, aman ditampilkan ke user. */
    private const ERROR_PREFIX = 'Maaf, gagal memproses laporan: ';

    /**
     * Pesan generik utk SEMUA kegagalan tak terduga (exception apa pun di
     * kedua try/catch handle()) — SENGAJA tidak menyertakan $e->getMessage()
     * (FINAL REVIEW Finding 2), lihat catatan sama di LeadsReportFlow.
     */
    private const ERROR_GENERIC = 'Maaf, gagal memproses laporan. Coba lagi, atau hubungi admin.';

    /**
     * Alias header kolom .xlsx (dinormalisasi lower-case + spasi tunggal) ->
     * field kanonik periodData/summary. Desain sendiri (n8n tak punya jalur
     * xlsx utk Ads) — dukung label EN & ID yang lazim dipakai tim.
     */
    private const XLSX_HEADER_ALIASES = [
        'day' => 'day', 'hari' => 'day',
        'views' => 'views', 'tayangan' => 'views',
        'clicks' => 'clicks', 'klik' => 'clicks',
        'ctr' => 'ctr',
        'link clicks' => 'linkClicks', 'linkclicks' => 'linkClicks', 'klik link' => 'linkClicks',
        'spend' => 'spend', 'biaya' => 'spend', 'pengeluaran' => 'spend',
        'reach' => 'reach', 'jangkauan' => 'reach',
        'reach trend' => 'reachTrend', 'reachtrend' => 'reachTrend', 'trend reach' => 'reachTrend',
        'jabar' => 'jabar',
        'jatim' => 'jatim',
        'lain' => 'lain', 'lainnya' => 'lain',
    ];

    /**
     * Token pemisah bagian "harian" vs "ringkasan periode" pada teks naratif
     * AI — PORT PERSIS dari array TOKENS pada node n8n "Code in Generate
     * HTML1" (urutan dijaga apa adanya: dicoba satu-satu, berhenti di token
     * PERTAMA yang ketemu). Beda dgn Leads yang cuma 1 SPLIT_TOKEN tetap —
     * system prompt Ads OUTPUT FORMAT-nya memakai "📊 **Ringkasan Periode**"
     * (cocok TOKENS[0]), sisanya varian jaga-jaga dari sumber aslinya.
     */
    private const SPLIT_TOKENS = [
        '📊 **Ringkasan Periode**',
        '📊 *Ringkasan Periode*',
        '📊 Ringkasan Periode',
        '📈 SUMMARY',
        '📈 LAPORAN SUMMARY',
        '📊 SUMMARY',
        '📊 LAPORAN SUMMARY',
    ];

    /**
     * Instruksi utk ReportAi::readFile() — desain sendiri (bukan verbatim),
     * diturunkan dari bentuk JSON node n8n "Code Parse & Split Ads"
     * (periodData[]/summary/lastDay, field persis sama supaya downstream
     * analyze()/splitReport() tak perlu tahu file aslinya .xlsx atau .pdf).
     */
    private const READ_INSTRUCTION = <<<'READ_INSTRUCTION_EOT'
Ini adalah laporan performa iklan (Ads) harian dalam bentuk PDF. Berisi data per
hari (Day 1, Day 2, dst — views, clicks, CTR, link clicks, spend, reach, tren
reach, kadang rincian spend per wilayah Jabar/Jatim/Lain), biasanya diikuti satu
baris "Total" berisi rekap seluruh periode.

Baca dokumen ini, lalu kembalikan HANYA JSON (tanpa teks lain, tanpa pagar kode
markdown) dengan struktur PERSIS seperti berikut:

{
  "periodData": [
    {
      "day": number,
      "views": number|null,
      "clicks": number|null,
      "ctr": number|null,
      "linkClicks": number|null,
      "spend": number|null,
      "reach": number|null,
      "reachTrend": number|null,
      "jabar": number|null,
      "jatim": number|null,
      "lain": number|null
    }
  ],
  "summary": {
    "views": number|null,
    "clicks": number|null,
    "ctr": number|null,
    "linkClicks": number|null,
    "spend": number|null,
    "reach": number|null
  } | null,
  "lastDay": number
}

Aturan:
- "ctr" adalah angka CTR dalam persen — kirim sebagai angka biasa (mis. 65.4),
  BUKAN string berisi tanda %.
- "jabar"/"jatim"/"lain" adalah rincian spend per wilayah utk baris hari itu —
  null bila dokumen tidak memuat rincian wilayah utk hari tersebut.
- "summary" diambil dari baris rekap/"Total" di dokumen apa adanya — JANGAN
  dihitung ulang dari periodData. null bila dokumen tidak memuat baris Total.
- "lastDay" = nilai "day" pada baris hari TERAKHIR yang datanya terisi di
  dokumen (bukan diperkirakan).
- Sel kosong atau "-" jadi null — JANGAN diisi 0 atau ditebak.
- JANGAN mengarang, membulatkan, atau memperkirakan angka yang tidak terbaca
  dengan jelas.
- Sertakan SEMUA baris harian apa adanya sesuai urutan pada dokumen (tidak
  perlu diurutkan).
READ_INSTRUCTION_EOT;

    /**
     * DI-PORT DARI system message node n8n "AI Daily ADS Analyzer"
     * (scratchpad/n8n_full.txt baris ~588-722) — VERBATIM byte-identik KECUALI
     * satu edit bertarget dari FIX ROUND 1 (review, lihat dokblok kelas):
     *   - 2x baris "Brand: {{ $('Switch').item.json.message.document.file_name
     *     .replace('.pdf','').split(' ').slice(0,1).join(' ') }}" & 1x baris
     *     "{{ $json }}" (ekspresi n8n yang MATI di PHP — tak pernah ter-resolve,
     *     jadi dulu terkirim mentah2 ke AI sbg teks tak bermakna) DIBUANG,
     *     diganti SATU kalimat instruksi eksplisit: 'Gunakan nilai field
     *     "brand" dari input JSON sebagai nama brand di judul.' — brand kini
     *     genuinely field JSON asli (lihat handle()/deriveBrand()), PERSIS pola
     *     "{{lastDay}}" yang sudah lebih dulu valid krn lastDay memang field
     *     JSON asli.
     * SISANYA — seluruh business rule, rumus CPC/CPM, & OUTPUT FORMAT (termasuk
     * token "{{brand}}"/"{{lastDay}}" itu sendiri, TIDAK disentuh) — tetap
     * byte-identik ke sumber n8n, JANGAN diparafrase lebih jauh dari edit di
     * atas. Nowdoc dipakai spy PHP tak coba men-interpolasi apa pun sbg
     * variabel PHP.
     */
    private const ADS_SYSTEM_PROMPT = <<<'ADS_SYSTEM_PROMPT_EOT'
Kamu adalah analis digital marketing berfokus pada optimasi kampanye iklan.

Gunakan nilai field "brand" dari input JSON sebagai nama brand di judul.

Berikut adalah dataset performa iklan bulan ini dalam bentuk JSON.

Gunakan hanya data pada "periodData".
Hari terakhir update = Day {{lastDay}}.
Jangan analisa day setelahnya atau data kosong.

Tugasmu:

1. Identifikasi hari terbaru yang memiliki data (day = {{lastDay}}).
   Buat analisis mendalam khusus untuk hari tersebut (itu adalah data "kemarin").

   Tampilkan:
   • Views, Clicks, CTR, CPC, CPM  
     (CPC = spend / linkClicks, CPM = spend / reach * 1000)
   • Status kualitas views:
       <400 = 🚨 buruk  
       400–600 = ⚠ normal  
       >600 = 🟢 sangat baik
   • CTR indikator:
       <65% = 📉 buruk  
       ≥65% = 📈 bagus
   • Insight cepat tentang performa hari tersebut
   • Jika clicks = 0 atau reach = 0, beri catatan khusus

2. Buat ringkasan performa periode Day 1 – Day {{lastDay}}:
   • Total Spend
   • Total Reach
   • Total Clicks
   • Rata-rata CTR periode
   • Best Day = hari paling efisien (CTR tinggi / CPC rendah) + alasan
   • Worst Day = performa terlemah + penyebab
   • Trend performa:
       - Arah CTR (naik / turun / stagnan)
       - Perbandingan hari awal vs hari terakhir
       - Perubahan efisiensi biaya (CPC / CPM)

3. Berikan rekomendasi actionable untuk hari selanjutnya:
   • Apa yang harus diperbaiki
   • Apa yang harus dipertahankan
   • Ide eksperimen konten / headline / creative
   • Strategi optimasi budget berdasarkan data

4. **Analisis Distribusi Budget Wilayah (Jabar / Jatim / Lain)**

   a. Analisis HARI TERAKHIR (Day {{lastDay}}):
   • Tampilkan nominal spend:
       - Jabar: RpX
       - Jatim: RpX
       - Lain: RpX
   • Sajikan dalam bentuk perbandingan persentase dari total spend hari tersebut
   • Jelaskan wilayah mana yang:
       - Menyerap budget terbesar
       - Paling kecil kontribusinya
   • Insight singkat: apakah distribusi ini sehat / timpang / perlu penyesuaian

   b. Analisis TOTAL PERIODE (Day 1 – Day {{lastDay}}):
   • Total spend kumulatif:
       - Total Jabar
       - Total Jatim
       - Total Lain
   • Presentasikan sebagai komposisi budget (%)
   • Interpretasi:
       - Wilayah dominan
       - Ketergantungan pada satu wilayah
       - Potensi risiko atau peluang scaling

   c. Rekomendasi Regional:
   • Wilayah mana yang layak ditambah budget
   • Wilayah mana yang perlu dikontrol atau dievaluasi
   • Catatan jika ada wilayah dengan spend rendah tapi performa baik

Format output WAJIB menggunakan struktur berikut:

📅 **Laporan Iklan Harian {{brand}}**  
Periode: Day 1 – Day {{lastDay}}

🟦 **Analisis Kemarin (Day {{lastDay}})**  
Views: X → {status kualitas}  
Clicks: X  
Link Clicks: X  
Reach: X  
Spend: RpX  
CTR: X% {📈/📉}  
CPC: RpX  
CPM: RpX  

🌍 **Distribusi Budget Wilayah**

🔹 Day {{lastDay}}  
• Jabar: RpX (X%)  
• Jatim: RpX (X%)  
• Lain: RpX (X%)  
Insight: ...

📊 **Ringkasan Periode**  
• Total Spend: RpX  
• Total Clicks: X  
• Total Reach: X  
• Rata-rata CTR: X%  
• Best Day: Day X (alasan)  
• Worst Day: Day X (alasan)  
• Trend: meningkat / stabil / menurun → penjelasan singkat

🌍 **Distribusi Budget Wilayah**

🔹 Total Periode  
• Jabar: RpX (X%)  
• Jatim: RpX (X%)  
• Lain: RpX (X%)  
Insight: ...

🧠 **Rekomendasi Besok**  
- ...
- ...
- ...

**Aturan Perhitungan (WAJIB):**
CPC = spend / linkClicks  
CPM = (spend / reach) * 1000  

Jika linkClicks = 0 → CPC = 0  
Jika reach = 0 → CPM = 0  

Gunakan angka persis dari dataset.
Jangan membuat asumsi atau estimasi.
Jangan menampilkan JSON mentah.
Tulis ringkas, tajam, dan langsung ke insight bisnis.
ADS_SYSTEM_PROMPT_EOT;

    public function __construct(private TelegramClient $telegram) {}

    /**
     * @param  array<string,mixed>  $document  message.document dari update Telegram (file_id/file_name/mime_type).
     */
    public function handle(int|string $chatId, array $document): void
    {
        $fileId = (string) ($document['file_id'] ?? '');
        $fileName = (string) ($document['file_name'] ?? '');
        $mime = (string) ($document['mime_type'] ?? 'application/pdf');

        $ai = app(ReportAi::class);
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        try {
            $filePath = (string) ($this->telegram->getFile($fileId)['result']['file_path'] ?? '');
            $bytes = $this->telegram->downloadFile($filePath);

            $json = $ext === 'xlsx'
                ? $this->fromXlsxRows($this->withTempFile($bytes, fn (string $path) => SpreadsheetReader::rows($path, 'xlsx')))
                : $this->jsonFromPdf($bytes, $mime, $ai);
        } catch (Throwable $e) {
            Log::error('report-bot ads: unduh/susun-json gagal', ['e' => $e->getMessage()]);
            $this->telegram->sendMessage($chatId, self::ERROR_GENERIC);

            return;
        }

        if (($json['periodData'] ?? []) === []) {
            $this->telegram->sendMessage($chatId, self::ERROR_PREFIX.'tidak ada data performa iklan yang berhasil terbaca dari file.');

            return;
        }

        // FIX ROUND 1: satu titik, dipasang SETELAH percabangan xlsx/pdf (bukan
        // per-return-site di tiap method susun-JSON) supaya SEMUA jalur otomatis
        // kebagian field "brand" tanpa perlu mengubah signature fromXlsxRows()/
        // fromAdsText()/jsonFromPdf() (yang murni soal data laporan, tak perlu
        // tahu soal nama file) — lihat dokblok kelas & ADS_SYSTEM_PROMPT.
        $json['brand'] = $this->deriveBrand($fileName);

        try {
            $text = $ai->analyze(self::ADS_SYSTEM_PROMPT, $json);

            $html = view('report_bot.ads', $this->splitReport($text))->render();

            $this->telegram->sendDocument($chatId, 'Laporan Ads.html', $html);
        } catch (Throwable $e) {
            Log::error('report-bot ads: analisis/kirim gagal', ['e' => $e->getMessage()]);
            $this->telegram->sendMessage($chatId, self::ERROR_GENERIC);
        }
    }

    /**
     * Jalur .pdf: ekstrak teks -> looksUnreadable()? ReportAi::readFile
     * (multimodal) : parse manual (fromAdsText). Method terpisah dari handle()
     * murni supaya percabangan xlsx/pdf di handle() ringkas dibaca; readFile()
     * TETAP di dalam try/catch tunggal handle() (dipanggil dari sana), bukan
     * di sini, supaya satu try/catch itu konsisten membungkus SELURUH langkah
     * "susun JSON" apa pun cabangnya.
     *
     * @return array{periodData: array<int, array<string, mixed>>, summary: array<string, mixed>|null, lastDay: int}
     */
    private function jsonFromPdf(string $bytes, string $mime, ReportAi $ai): array
    {
        $text = $this->withTempFile($bytes, fn (string $path) => PdfTextExtractor::extract($path));

        if (PdfTextExtractor::looksUnreadable($text)) {
            $json = $ai->readFile($bytes, $mime, self::READ_INSTRUCTION);

            return [
                'periodData' => is_array($json['periodData'] ?? null) ? $json['periodData'] : [],
                'summary' => is_array($json['summary'] ?? null) ? $json['summary'] : null,
                'lastDay' => (int) ($json['lastDay'] ?? 0),
            ];
        }

        return $this->fromAdsText($text);
    }

    /**
     * Tulis $bytes ke file sementara, jalankan $fn($path), SELALU hapus file
     * sementara itu (finally) — SpreadsheetReader::rows()/PdfTextExtractor::extract()
     * menerima PATH, bukan bytes mentah hasil TelegramClient::downloadFile().
     */
    private function withTempFile(string $bytes, callable $fn): mixed
    {
        $path = tempnam(sys_get_temp_dir(), 'ads_');
        file_put_contents($path, $bytes);

        try {
            return $fn($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * FIX ROUND 1: turunkan "brand" dari nama file, bukan lewat ekspresi n8n
     * mati (lihat dokblok kelas). n8n aslinya cuma ambil KATA PERTAMA nama
     * file (`.split(' ').slice(0,1)`) — utk nama file spt "5. Rave Tailor Mei
     * Report Ad.xlsx" itu cuma menghasilkan "5." (rusak, bukan cuma "mati").
     * Di sini: buang ekstensi, buang prefiks penomoran list di depan (mis.
     * "5. "/"12) " — artefak urutan file, bukan bagian nama brand), buang
     * akhiran " - <noise>" tunggal di belakang (mis. " - Linktree AI" — noise
     * vendor/tool export; kalau ada BEBERAPA " - ", cuma segmen TERAKHIR yang
     * dibuang, bukan digreedy-strip dari kemunculan pertama), lalu trim —
     * hasilnya "batang nama file" yang sudah bersih dipakai APA ADANYA (BUKAN
     * usaha lebih jauh menebak "kata mana persis nama brand-nya" — itu butuh
     * heuristik NLP yang tak bisa diverifikasi tanpa sampel nama file Ads
     * nyata; lihat Concerns di task-10-report.md). Fallback "Brand" (nama sama
     * dgn fallback n8n sendiri) bila hasil pembersihan kosong.
     */
    private function deriveBrand(string $fileName): string
    {
        $stem = pathinfo($fileName, PATHINFO_FILENAME);
        $stem = (string) preg_replace('/^\s*\d+[.\)]\s*/', '', $stem);
        $stem = (string) preg_replace('/\s+-\s+[^-]*$/', '', $stem);
        $stem = trim($stem, " \t\n\r\0\x0B.-");

        return $stem !== '' ? $stem : 'Brand';
    }

    /**
     * Susun baris .xlsx (baris 0 = header) jadi bentuk JSON {periodData[],
     * summary,lastDay} — desain sendiri (lihat XLSX_HEADER_ALIASES), bukan
     * port n8n. Baris dgn sel pertama memuat kata "total" (case-insensitive)
     * diperlakukan sbg baris rekap -> summary; baris lain -> satu elemen
     * periodData. Baris kosong (sel pertama kosong) dilewati.
     *
     * @param  array<int, array<int, string>>  $rows
     * @return array{periodData: array<int, array<string, mixed>>, summary: array<string, mixed>|null, lastDay: int}
     */
    private function fromXlsxRows(array $rows): array
    {
        if ($rows === []) {
            return ['periodData' => [], 'summary' => null, 'lastDay' => 0];
        }

        $colMap = [];
        foreach ($rows[0] as $i => $name) {
            $key = self::XLSX_HEADER_ALIASES[$this->normalizeHeader((string) $name)] ?? null;
            if ($key !== null && ! isset($colMap[$key])) {
                $colMap[$key] = $i;
            }
        }

        $period = [];
        $summary = null;

        foreach (array_slice($rows, 1) as $row) {
            $first = trim((string) ($row[0] ?? ''));
            if ($first === '') {
                continue;
            }

            if (stripos($first, 'total') !== false) {
                $summary = [
                    'views' => self::toNum($this->cell($row, $colMap, 'views')),
                    'clicks' => self::toNum($this->cell($row, $colMap, 'clicks')),
                    'ctr' => self::toPct($this->cell($row, $colMap, 'ctr')),
                    'linkClicks' => self::toNum($this->cell($row, $colMap, 'linkClicks')),
                    'spend' => self::toNum($this->cell($row, $colMap, 'spend')),
                    'reach' => self::toNum($this->cell($row, $colMap, 'reach')),
                ];

                continue;
            }

            $period[] = [
                'day' => self::toNum($this->cell($row, $colMap, 'day')),
                'views' => self::toNum($this->cell($row, $colMap, 'views')),
                'clicks' => self::toNum($this->cell($row, $colMap, 'clicks')),
                'ctr' => self::toPct($this->cell($row, $colMap, 'ctr')),
                'linkClicks' => self::toNum($this->cell($row, $colMap, 'linkClicks')),
                'spend' => self::toNum($this->cell($row, $colMap, 'spend')),
                'reach' => self::toNum($this->cell($row, $colMap, 'reach')),
                'reachTrend' => self::toSignedNum($this->cell($row, $colMap, 'reachTrend')),
                'jabar' => $this->nullableCell($row, $colMap, 'jabar'),
                'jatim' => $this->nullableCell($row, $colMap, 'jatim'),
                'lain' => $this->nullableCell($row, $colMap, 'lain'),
            ];
        }

        return [
            'periodData' => $period,
            'summary' => $summary,
            'lastDay' => $period === [] ? 0 : (int) $period[count($period) - 1]['day'],
        ];
    }

    /**
     * PORT PERSIS dari node n8n "Code Parse & Split Ads": tokenisasi SELURUH
     * teks jadi runtun angka/persen/tanda kurung/minus, lalu telan blok 8
     * token (day,views,clicks,ctr%,linkClicks,spend,reach,reachTrend) selama
     * token ke-4 blok itu mengandung "%" (penanda "ini blok hari yang valid").
     * Sesudah tiap blok, INTIP 3 token berikutnya: kalau ada & token ke-4
     * SETELAHNYA bukan "%" (bukan awal blok hari lain) -> 3 token itu dianggap
     * rincian wilayah (jabar/jatim/lain) & ikut ditelan. "Total ..." dicari
     * TERPISAH lewat regex sendiri (bukan loop utama) persis sumbernya.
     *
     * Heuristik posisional ini persis sumber JS-nya, termasuk sisi rapuhnya:
     * kalau baris "Total" nempel LANGSUNG setelah baris harian yang TAK
     * punya rincian wilayah sendiri, 3 angka pertama "Total" akan salah
     * "ditelan" sbg rincian wilayah hari itu (bug laten di sumber n8n, bukan
     * di-introduce oleh porting ini) — lihat AdsFlowTest utk kasus yang
     * sengaja dirancang menghindarinya (baris harian dgn rincian wilayah
     * sendiri, persis pola realistis yang membuat loop berhenti tepat di
     * awal baris Total).
     *
     * @return array{periodData: array<int, array<string, mixed>>, summary: array<string, mixed>|null, lastDay: int}
     */
    private function fromAdsText(string $text): array
    {
        if (trim($text) === '') {
            return ['periodData' => [], 'summary' => null, 'lastDay' => 0];
        }

        preg_match_all('/[\d.,%()\-]+/', $text, $m);
        $tokens = $m[0];
        $count = count($tokens);

        $period = [];
        $i = 0;

        while ($i < $count) {
            $block = array_slice($tokens, $i, 8);
            if (count($block) < 8 || ! str_contains($block[3], '%')) {
                break;
            }

            $cursor = $i + 8;
            $jabar = $jatim = $lain = null;

            $hasRegional = isset($tokens[$cursor], $tokens[$cursor + 1], $tokens[$cursor + 2]);
            $nextLooksLikeDaily = isset($tokens[$cursor + 3]) && str_contains($tokens[$cursor + 3], '%');

            if ($hasRegional && ! $nextLooksLikeDaily) {
                $jabar = self::toNum($tokens[$cursor]);
                $jatim = self::toNum($tokens[$cursor + 1]);
                $lain = self::toNum($tokens[$cursor + 2]);
                $cursor += 3;
            }

            $period[] = [
                'day' => self::toNum($block[0]),
                'views' => self::toNum($block[1]),
                'clicks' => self::toNum($block[2]),
                'ctr' => self::toPct($block[3]),
                'linkClicks' => self::toNum($block[4]),
                'spend' => self::toNum($block[5]),
                'reach' => self::toNum($block[6]),
                'reachTrend' => self::toSignedNum($block[7]),
                'jabar' => $jabar,
                'jatim' => $jatim,
                'lain' => $lain,
            ];

            $i = $cursor;
        }

        $summary = null;
        if (preg_match('/Total\s+([\d.,]+)\s+([\d.,]+)\s+([\d.,%]+)\s+([\d.,]+)\s+([\d.,]+)\s+([\d.,]+)/i', $text, $sm) === 1) {
            $summary = [
                'views' => self::toNum($sm[1]),
                'clicks' => self::toNum($sm[2]),
                'ctr' => self::toPct($sm[3]),
                'linkClicks' => self::toNum($sm[4]),
                'spend' => self::toNum($sm[5]),
                'reach' => self::toNum($sm[6]),
            ];
        }

        return [
            'periodData' => $period,
            'summary' => $summary,
            'lastDay' => $period === [] ? 0 : (int) $period[count($period) - 1]['day'],
        ];
    }

    /**
     * Port dari node n8n "Code in Generate HTML1": coba tiap SPLIT_TOKENS
     * berurutan, pecah di token PERTAMA yang ketemu (summary MEMUAT token
     * itu sendiri, persis `summaryText = reportText.substring(idx)` sumbernya).
     *
     * @return array{daily:string,summary:string}
     */
    private function splitReport(string $reportText): array
    {
        foreach (self::SPLIT_TOKENS as $token) {
            if (str_contains($reportText, $token)) {
                [$daily, $rest] = explode($token, $reportText, 2);

                return ['daily' => $daily, 'summary' => $token.$rest];
            }
        }

        return ['daily' => $reportText, 'summary' => ''];
    }

    /** Sel mentah (string, sudah trim) utk kolom kanonik $key — '' bila kolom/baris tak ada. */
    private function cell(array $row, array $colMap, string $key): string
    {
        return trim((string) ($row[$colMap[$key] ?? -1] ?? ''));
    }

    /**
     * Sama seperti cell(), tapi sel kosong ATAU "-" -> null (bukan 0) — utk
     * jabar/jatim/lain yang boleh tak ada. "-" sbg penanda sel kosong
     * mengikuti konvensi yang sama dgn READ_INSTRUCTION Leads ("Sel kosong
     * atau '-' jadi null").
     */
    private function nullableCell(array $row, array $colMap, string $key): ?int
    {
        $raw = $this->cell($row, $colMap, $key);

        return $raw === '' || $raw === '-' ? null : self::toNum($raw);
    }

    private function normalizeHeader(string $s): string
    {
        return trim((string) preg_replace('/\s+/', ' ', mb_strtolower($s)));
    }

    /**
     * PORT dari `toNum` node "Code Parse & Split Ads": buang semua karakter
     * selain digit 0-9 (termasuk tanda minus!), lalu cast ke int. Selalu
     * non-negatif — persis sumbernya (tanda minus ditangani terpisah oleh
     * toSignedNum utk field reachTrend, BUKAN oleh toNum).
     */
    private static function toNum(string $v): int
    {
        return (int) preg_replace('/[^0-9]/', '', $v);
    }

    /**
     * PORT dari `toPct`: buang tanda "%" PERTAMA & koma PERTAMA (jadi "."),
     * lalu parse sbg angka — 0.0 bila hasilnya bukan angka valid (setara
     * `Number(str) || 0` sisi JS, yang balik 0 utk NaN/"").
     */
    private static function toPct(string $v): float
    {
        $s = self::replaceFirst('%', '', $v);
        $s = self::replaceFirst(',', '.', $s);

        return is_numeric($s) ? (float) $s : 0.0;
    }

    /** PORT dari `toSignedNum`: negatif bila mengandung "(" atau "-", besarannya lewat toNum(). */
    private static function toSignedNum(string $v): int
    {
        $negative = str_contains($v, '(') || str_contains($v, '-');
        $n = self::toNum($v);

        return $negative ? -$n : $n;
    }

    /** Ganti kemunculan PERTAMA saja $search->$replace (setara String.replace(str,str) sisi JS, bukan replace-all). */
    private static function replaceFirst(string $search, string $replace, string $subject): string
    {
        $pos = strpos($subject, $search);

        return $pos === false ? $subject : substr_replace($subject, $replace, $pos, strlen($search));
    }
}
