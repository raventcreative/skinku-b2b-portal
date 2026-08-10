<?php

namespace App\Services\ReportBot\Flows;

use App\Services\ReportBot\ReportAi;
use App\Services\ReportBot\TelegramClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Task 9: flow Leads — laporan pertama Report Bot. Alur: unduh PDF Leads
 * (TelegramClient) -> AI baca PDF jadi JSON per-CS (ReportAi::readFile,
 * multimodal — PDF Leads ber-font CID, tidak terekstrak bersih lewat
 * PdfTextExtractor, jadi AI langsung "membaca" berkasnya) -> agregasi H-1 +
 * period MURNI di PHP (port node n8n "Code in parse", tidak ada angka yang
 * datang dari AI) -> AI narasikan tiap CS (ReportAi::analyze, system prompt
 * verbatim dari node "AI Daily Report Analyzer") -> gabung semua output CS ke
 * satu file HTML (port node n8n "Code in Generate HTML") -> kirim via
 * TelegramClient::sendDocument.
 *
 * ReportAi TIDAK menangkap App\Services\Ai\AiException sendiri (lihat
 * dokblok ReportAi) — di sinilah try/catch-nya. DUA try/catch: (1) unduh
 * (getFile/downloadFile) + readFile, (2) loop analyze + render HTML +
 * sendDocument — gagal di titik manapun -> pesan GENERIK ke user via
 * sendMessage (FINAL REVIEW Finding 3: unduh & sendDocument dulu di LUAR
 * try/catch, sekarang ikut dibungkus supaya kegagalan network saat unduh/
 * kirim juga dapat balasan, bukan diam), TIDAK ada sendDocument kalau gagal.
 * Detail exception (bisa memuat data sensitif, mis. token — lihat
 * TelegramClient::send()) di-Log::error() SERVER-SIDE saja, TIDAK PERNAH
 * diteruskan ke user (FINAL REVIEW Finding 2, sebelumnya $e->getMessage()
 * mentah ikut terkirim ke chat).
 */
class LeadsReportFlow
{
    /** Prefix pesan VALIDASI (bukan exception) — dipakai apa adanya, aman ditampilkan ke user. */
    private const ERROR_PREFIX = 'Maaf, gagal memproses laporan: ';

    /**
     * Pesan generik utk SEMUA kegagalan tak terduga (exception apa pun di
     * kedua try/catch handle()) — SENGAJA tidak menyertakan $e->getMessage()
     * (FINAL REVIEW Finding 2): detail asli exception bisa memuat info
     * sensitif (mis. token Telegram lewat ConnectionException, lihat
     * TelegramClient::send()) atau sekadar membingungkan user non-teknis.
     * Detail lengkap tetap tercatat via Log::error() di titik lempar.
     */
    private const ERROR_GENERIC = 'Maaf, gagal memproses laporan. Coba lagi, atau hubungi admin.';

    /**
     * Marker yang membelah output naratif satu CS jadi bagian harian vs
     * summary — persis SPLIT_TOKEN pada node n8n "Code in Generate HTML".
     * Muncul apa adanya di OUTPUT FORMAT system prompt di bawah (📊 beda
     * dengan 📈 pada header kolom HTML statis — memang begitu di sumber
     * aslinya, bukan salah ketik port ini).
     */
    private const SPLIT_TOKEN = '📊 *LAPORAN SUMMARY*';

    /** Field numerik per baris (harian maupun total) — urutan tidak signifikan, cuma dikirim sbg JSON ke AI. */
    private const NUMERIC_FIELDS = ['leads', 'closing', 'rate', 'omzet', 'jumlahDress', 'avgSales', 'avgDress', 'dressRate'];

    /**
     * Instruksi utk ReportAi::readFile() — desain sendiri (bukan verbatim),
     * diturunkan dari bentuk data node n8n "Code Parse & Split" (parsing PDF
     * per blok CS: baris2 harian bertanggal lalu satu baris total/rekap).
     */
    private const READ_INSTRUCTION = <<<'READ_INSTRUCTION_EOT'
Ini adalah laporan Leads harian, berisi beberapa blok data — satu blok per Customer Service (CS).
Setiap blok terdiri dari beberapa baris harian (satu baris per tanggal) diikuti satu baris
total/rekap di akhir blok (biasanya memuat nama CS + angka akumulasi seluruh periode).

Baca SETIAP blok CS pada dokumen ini, lalu kembalikan HANYA JSON array (tanpa teks lain,
tanpa pagar kode markdown), satu elemen per CS, dengan struktur PERSIS seperti berikut:

[
  {
    "csName": "nama CS",
    "dailyRows": [
      {
        "date": "YYYY-MM-DD",
        "leads": number|null,
        "closing": number|null,
        "rate": number|null,
        "omzet": number|null,
        "jumlahDress": number|null,
        "avgSales": number|null,
        "avgDress": number|null,
        "dressRate": number|null
      }
    ],
    "total": {
      "leads": number|null,
      "closing": number|null,
      "rate": number|null,
      "omzet": number|null,
      "jumlahDress": number|null,
      "avgSales": number|null,
      "avgDress": number|null,
      "dressRate": number|null
    }
  }
]

Aturan:
- "rate" dan "dressRate" adalah angka closing/dress rate dalam persen — kirim sebagai angka
  biasa (mis. 12.5), BUKAN string berisi tanda %.
- Sel kosong atau "-" jadi null — JANGAN diisi 0 atau ditebak.
- "total" diambil dari baris rekap/total di dokumen apa adanya — JANGAN dihitung ulang dari
  dailyRows.
- JANGAN mengarang, membulatkan, atau memperkirakan angka yang tidak terbaca dengan jelas.
- Sertakan SEMUA baris harian apa adanya sesuai urutan pada dokumen (tidak perlu diurutkan).
READ_INSTRUCTION_EOT;

    /**
     * DI-PORT VERBATIM dari system message node n8n "AI Daily Report
     * Analyzer" (scratchpad/n8n_full.txt baris ~84-199). JANGAN diparafrase —
     * termasuk business rule (target CS Rp2.800.000/closing >=10%,
     * pengecualian nama Bobby/Alfin/Surya/Danu Rp3.500.000/>=25%, target
     * bulanan >Rp85jt / >Rp100jt utk nama pengecualian) dan OUTPUT FORMAT
     * strict-nya.
     */
    private const LEADS_SYSTEM_PROMPT = <<<'LEADS_SYSTEM_PROMPT_EOT'
You are a business report analysis assistant.

You will receive structured JSON data generated from a daily business report.
This data may contain multiple individuals (anak / sales / CS).

────────────────────────
TASKS
────────────────────────
1. Use ONLY the provided input data. Do NOT infer, estimate, or guess missing values.
2. For each date explicitly present in the input:
   - Treat it as an individual daily report.
   - Also treat the full date range as a summary total period (from first date to last date).
3. Generate:
   - A short performance summary PER individual (1–2 sentences, Bahasa Indonesia).
   - One overall daily summary (2–3 sentences, Bahasa Indonesia).
4. Detect and explicitly mention issues such as:
   - Missing values (null)
   - Zero or undefined closingRate
   - Incomplete daily records
5. All numeric evaluations MUST strictly come from input data.

────────────────────────
IMPORTANT RULES
────────────────────────
- DO NOT hallucinate or invent data.
- NEVER estimate or project numbers.
- Dates MUST come only from the input data.
- All analysis, notes, and recommendations MUST be written in Bahasa Indonesia.
- Use professional business Indonesian tone.

────────────────────────
BUSINESS KNOWLEDGE (MANDATORY REFERENCE)
────────────────────────
Use the following business benchmarks ONLY as analytical references.
Do NOT modify, infer, or calculate missing values beyond the given data.

1. Daily CS Revenue Target:
   - Each Customer Service (CS) has a daily revenue target of Rp 2.800.000.
   - If actual omzet is below this number, explicitly mention it as "di bawah target".
   - If equal or above, mention it as "memenuhi" or "melampaui target".

2. Ideal Closing Rate Standard:
   - A healthy closing rate is 10% or higher. 
   - If closing rate is below 10%, clearly state that performance is not optimal.
   - If closing rate is 0%, null, or undefined, mark it as a data issue.

**Special Exception Rule for Specific CS Names:**
   - For CS with names: Bobby, Alfin, Surya, Danu → the ideal closing rate threshold is 25% instead of 10%
- For CS with names: Bobby, Alfin, Surya, Danu → revenue target Rp 3.500.000 per day
   - If closing rate for any of these names is below 25%, clearly indicate that performance is not optimal.
   - If closing rate meets or exceeds 25%, categorize as good performance.

3. Monthly Revenue Goal:
   - The company’s desired monthly revenue target is above Rp 85.000.000. 
**Special Exception Rule for Specific CS Names**
- For CS with names: Bobby, Alfin, Surya, Danu → the The company’s desired monthly revenue target is above Rp 100.000.000.
   - In period summary:
     - Compare total omzet in the provided period against this benchmark.
     - State whether the current performance is "on track", "belum tercapai", or "melampaui target",
       WITHOUT projecting future values.

⚠️ IMPORTANT:
These benchmarks are for qualitative evaluation only.
DO NOT calculate missing days, projections, or assumptions.

────────────────────────
OUTPUT FORMAT (STRICT – DO NOT DEVIATE)
────────────────────────

📊 *LAPORAN HARIAN*

📅 *Tanggal:* {{tanggal_harian}}
👤 *Nama:* *{{nama}}*

━━━━━━━━━━━━━━━━━━
💼 *RINGKASAN KEUANGAN*
━━━━━━━━━━━━━━━━━━
Omzet: {{omzet_harian}}
Leads: {{leads_harian}}
Closing: {{closing}}
Closing rate: {{closing_rate_harian}}

━━━━━━━━━━━━━━━━━━
📝 *ANALISIS & CATATAN*
━━━━━━━━━━━━━━━━━━
{{analisis_harian}}

━━━━━━━━━━━━━━━━━━
📌 *REKOMENDASI*
━━━━━━━━━━━━━━━━━━
{{rekomendasi_harian}}

—

📊 *LAPORAN SUMMARY*

📅 *Tanggal:* {{tanggal_awal}} – {{tanggal_akhir}}
👤 *Nama:* *{{nama}}*

━━━━━━━━━━━━━━━━━━
💼 *RINGKASAN KEUANGAN*
━━━━━━━━━━━━━━━━━━
Omzet: {{omzet_periode}}
Leads: {{leads_periode}}
Closing: {{closing}}
Closing rate: {{closing_rate_periode}}

━━━━━━━━━━━━━━━━━━
📝 *ANALISIS & CATATAN*
━━━━━━━━━━━━━━━━━━
{{analisis_periode}}

━━━━━━━━━━━━━━━━━━
📌 *REKOMENDASI*
━━━━━━━━━━━━━━━━━━
{{rekomendasi_periode}}
LEADS_SYSTEM_PROMPT_EOT;

    public function __construct(private TelegramClient $telegram) {}

    /**
     * @param  array<string,mixed>  $document  message.document dari update Telegram (file_id/file_name/mime_type).
     */
    public function handle(int|string $chatId, array $document): void
    {
        $fileId = (string) ($document['file_id'] ?? '');
        $mime = (string) ($document['mime_type'] ?? 'application/pdf');

        $ai = app(ReportAi::class);

        try {
            $filePath = (string) ($this->telegram->getFile($fileId)['result']['file_path'] ?? '');
            $bytes = $this->telegram->downloadFile($filePath);

            $csList = $ai->readFile($bytes, $mime, self::READ_INSTRUCTION);
        } catch (Throwable $e) {
            Log::error('report-bot leads: unduh/readFile gagal', ['e' => $e->getMessage()]);
            $this->telegram->sendMessage($chatId, self::ERROR_GENERIC);

            return;
        }

        if ($csList === []) {
            $this->telegram->sendMessage($chatId, self::ERROR_PREFIX.'tidak ada data CS yang berhasil terbaca dari file.');

            return;
        }

        $sections = [];

        try {
            foreach ($csList as $cs) {
                $payload = $this->aggregate(is_array($cs) ? $cs : []);
                $text = $ai->analyze(self::LEADS_SYSTEM_PROMPT, $payload);

                $sections[] = ['csName' => $payload['csName'] ?? null] + $this->splitReport($text);
            }

            $html = view('report_bot.leads', ['sections' => $sections])->render();

            $this->telegram->sendDocument($chatId, 'Laporan Leads.html', $html);
        } catch (Throwable $e) {
            Log::error('report-bot leads: analisis/kirim gagal', ['e' => $e->getMessage()]);
            $this->telegram->sendMessage($chatId, self::ERROR_GENERIC);
        }
    }

    /**
     * Port dari node n8n "Code in parse": urutkan dailyRows per tanggal,
     * ambil baris TERAKHIR setelah diurutkan (H-1), susun period {from,to,
     * ...} dari `total` apa adanya (bukan dihitung ulang dari dailyRows).
     * Semua angka murni pass-through dari JSON hasil ReportAi::readFile —
     * tidak ada nilai yang dihitung/ditebak PHP maupun AI di sini.
     *
     * @param  array<string,mixed>  $cs  {csName, dailyRows: array<array<string,mixed>>, total: array<string,mixed>}
     * @return array<string,mixed>
     */
    private function aggregate(array $cs): array
    {
        $csName = $cs['csName'] ?? null;
        $dailyRows = is_array($cs['dailyRows'] ?? null) ? $cs['dailyRows'] : [];
        $total = is_array($cs['total'] ?? null) ? $cs['total'] : [];

        if (! $csName || $dailyRows === []) {
            // Sama seperti n8n "Code in parse": data kosong/tak valid tetap diteruskan
            // (dengan flag error) supaya AI Analyzer bisa menyebutkan datanya bermasalah,
            // bukan bikin seluruh flow berhenti gara2 satu CS.
            return ['csName' => $csName, 'error' => 'Data CS kosong atau tidak valid'];
        }

        usort($dailyRows, fn (array $a, array $b) => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));

        $h1Row = $dailyRows[array_key_last($dailyRows)];

        return [
            'csName' => $csName,
            'h1' => ['date' => $h1Row['date'] ?? null] + $this->numericFields($h1Row),
            'period' => [
                'from' => $dailyRows[0]['date'] ?? null,
                'to' => $h1Row['date'] ?? null,
            ] + $this->numericFields($total),
        ];
    }

    /** @param  array<string,mixed>  $row  @return array<string,mixed> */
    private function numericFields(array $row): array
    {
        $out = [];
        foreach (self::NUMERIC_FIELDS as $field) {
            $out[$field] = $row[$field] ?? null;
        }

        return $out;
    }

    /**
     * Port dari node n8n "Code in Generate HTML": pecah output naratif satu
     * CS jadi bagian harian & summary berdasarkan SPLIT_TOKEN. HTML-escaping
     * sengaja TIDAK dilakukan di sini — dilakukan di Blade view lewat
     * `e()`/`{{ }}` (setara escapeHtml() sisi n8n, giliran idiomatik Laravel).
     *
     * @return array{daily:string,summary:string}
     */
    private function splitReport(string $reportText): array
    {
        if (! str_contains($reportText, self::SPLIT_TOKEN)) {
            return ['daily' => $reportText, 'summary' => ''];
        }

        [$daily, $rest] = explode(self::SPLIT_TOKEN, $reportText, 2);

        return ['daily' => $daily, 'summary' => self::SPLIT_TOKEN.$rest];
    }
}
