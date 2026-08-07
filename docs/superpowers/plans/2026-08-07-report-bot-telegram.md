# Report Bot Telegram Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrasi bot Telegram n8n ("Daily Report") ke SKINKU sebagai kode Laravel: kirim file ke bot → analisis/olah → balas hasil, tanpa langganan n8n & tanpa menu fitur.

**Architecture:** Webhook publik `/telegram/webhook` (diverifikasi secret token) → gerbang kode akses per-chat → router (nama file + tipe) → 3 flow: Leads & Ads (ekstraksi PHP→fallback AI multimodal → analisis AI → HTML) dan TikTok Income (cache CSV + merge XLSX via `TikTokIncomeReportService` yang sudah ada → xlsx). Semua balasan via Telegram `sendDocument`/`sendMessage`.

**Tech Stack:** Laravel 13, PHP 8.3, Blade, Laravel `Http`, Eloquent, `AiProviderFactory` (OpenAI-compatible/OpenRouter, model multimodal), `SpreadsheetReader`/`XlsxWriter`/`TikTokIncomeReportService` (sudah ada), zlib bawaan.

## Global Constraints
- **Zero-dependency**: tanpa composer package baru. Blade + vanilla JS + Laravel Http + Eloquent saja. Deploy = `git pull` + `optimize:clear`.
- **Test runner lokal**: `C:\php83\php.exe artisan test`. Jalankan `C:\php83\php.exe vendor/bin/pint --dirty` sebelum tiap commit.
- **Migrasi berikutnya**: `2026_01_01_000073` (terakhir 000072).
- **Rahasia via `.env`**: `TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`. Token bot WAJIB di-rotate di BotFather (token lama bocor di file n8n).
- **AI**: lewat `AiProviderFactory::make()` → `AiProvider::chat(array $messages, array $tools): AiTurn`. Model harus multimodal untuk baca PDF Leads/Ads.
- **Webhook publik**: route di luar grup `['auth','role']`; dikecualikan dari CSRF; dilindungi secret token, bukan sesi login.
- **Prompt AI di-port VERBATIM** dari `C:\Users\DELL\Downloads\Daily Report FS(fix), Leads + Ads (Updgraded).json` (atau dump `scratchpad/n8n_full.txt`): node "AI Daily Report Analyzer" (Leads) & "AI Daily ADS Analyzer" (Ads).
- **Tanpa menu fitur**: hanya webhook + satu bagian kecil di halaman Pengaturan Sistem (gate `system_settings`).
- **Di luar cakupan**: flow FS, Creator List, arsip Drive, kode per-orang, cron.

## File Structure
- `config/services.php` — tambah blok `telegram` (token, webhook_secret).
- `bootstrap/app.php` — kecualikan `telegram/webhook` dari CSRF.
- `routes/web.php` — 1 route publik webhook + rute admin di grup `system_settings`.
- `app/Http/Controllers/TelegramWebhookController.php` — terima update, verifikasi secret, delegasi ke dispatcher.
- `app/Services/ReportBot/TelegramClient.php` — getFile/downloadFile/sendMessage/sendDocument.
- `app/Services/ReportBot/ReportBotGate.php` — gerbang kode akses + otorisasi chat.
- `app/Services/ReportBot/ReportBotRouter.php` — deteksi flow.
- `app/Services/ReportBot/ReportBotDispatcher.php` — rangkai gate→router→flow.
- `app/Services/ReportBot/ReportAi.php` — wrapper `AiProviderFactory` (multimodal + JSON).
- `app/Services/ReportBot/Flows/{LeadsReportFlow,AdsReportFlow,TikTokIncomeFlow}.php`.
- `app/Support/PdfTextExtractor.php` — ekstraktor PDF PHP murni (zlib).
- `app/Models/TelegramBotChat.php`, `app/Models/TelegramBotPendingFile.php`.
- `app/Http/Controllers/ReportBotAdminController.php` — rotate kode, daftar/cabut chat.
- `database/migrations/2026_01_01_000073_create_report_bot_tables.php`.
- `resources/views/report_bot/_admin.blade.php` — disisipkan ke `resources/views/settings/index.blade.php`.
- `resources/views/report_bot/{leads,ads}.blade.php` — template HTML report.
- Refactor: `app/Services/TikTokIncomeReportService.php` — tambah `toXlsxSheets(array $report): array` (dipindah dari `TikTokIncomeController::download`).
- Tes: `tests/Feature/ReportBot/*Test.php`, `tests/Unit/PdfTextExtractorTest.php`, fixtures di `tests/fixtures/report_bot/`.

---

## FASE 1 — Plumbing, gerbang, ekstraksi (bite-sized)

### Task 1: Config + webhook publik + verifikasi secret

**Files:**
- Modify: `config/services.php` (tambah blok `telegram`)
- Modify: `bootstrap/app.php` (CSRF except)
- Modify: `routes/web.php` (route publik)
- Create: `app/Http/Controllers/TelegramWebhookController.php`
- Test: `tests/Feature/ReportBot/WebhookSecurityTest.php`

**Interfaces:**
- Produces: `POST /telegram/webhook` (name `telegram.webhook`) → `TelegramWebhookController::handle(Request): Response`. Config `services.telegram.token`, `services.telegram.webhook_secret`.

- [ ] **Step 1: Failing test**
```php
// tests/Feature/ReportBot/WebhookSecurityTest.php
public function test_webhook_tolak_secret_salah_terima_secret_benar(): void
{
    config()->set('services.telegram.webhook_secret', 'rahasia123');
    $this->postJson('/telegram/webhook', ['update_id' => 1])->assertForbidden();
    $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'salah'])
        ->postJson('/telegram/webhook', ['update_id' => 1])->assertForbidden();
    $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'rahasia123'])
        ->postJson('/telegram/webhook', ['update_id' => 1])->assertOk();
}
```
- [ ] **Step 2: Run → fail** `C:\php83\php.exe artisan test --filter=WebhookSecurityTest` (Expected: 404/500 route belum ada).
- [ ] **Step 3: Implement**
  - `config/services.php`: `'telegram' => ['token' => env('TELEGRAM_BOT_TOKEN'), 'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET')],`
  - `bootstrap/app.php` di `->withMiddleware(function (Middleware $m) {...})`: `$m->validateCsrfTokens(except: ['telegram/webhook']);`
  - `routes/web.php` (di ATAS grup `['auth','role']`, sejajar grup `guest`): `Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle'])->name('telegram.webhook');`
  - Controller `handle`: bandingkan `hash_equals((string) config('services.telegram.webhook_secret'), (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))`; kalau tidak sama → `abort(403)`; kalau kosong konfignya → `abort(403)`; else `return response('', 200);`
- [ ] **Step 4: Run → pass**
- [ ] **Step 5: Commit** `feat(report-bot): webhook Telegram publik + verifikasi secret token`

### Task 2: TelegramClient

**Files:**
- Create: `app/Services/ReportBot/TelegramClient.php`
- Test: `tests/Feature/ReportBot/TelegramClientTest.php`

**Interfaces:**
- Produces: `TelegramClient::getFile(string $fileId): array` (return `result.file_path`); `downloadFile(string $filePath): string` (bytes); `sendMessage(int|string $chatId, string $text): void`; `sendDocument(int|string $chatId, string $filename, string $bytes, ?string $caption = null): void`. Token dari `config('services.telegram.token')`. Base `https://api.telegram.org/bot{token}` & file base `https://api.telegram.org/file/bot{token}`.

- [ ] **Step 1: Failing test** — `Http::fake` untuk `api.telegram.org/*`; panggil `sendMessage(123,'halo')`; `Http::assertSent(fn ($r) => str_contains($r->url(),'/sendMessage') && $r['chat_id']===123 && $r['text']==='halo')`. Juga tes `getFile` mem-parse `result.file_path`.
- [ ] **Step 2: Run → fail**
- [ ] **Step 3: Implement** — pakai `Http::asJson()->post(...)` untuk sendMessage; `Http::attach('document',$bytes,$filename)->post('.../sendDocument', ['chat_id'=>$chatId,'caption'=>$caption])` untuk sendDocument; `Http::get(fileBase.'/'.$filePath)->body()` untuk downloadFile. Semua lempar `\RuntimeException` bila `! $res->successful()`.
- [ ] **Step 4: Run → pass**
- [ ] **Step 5: Commit** `feat(report-bot): TelegramClient (get/download/send)`

### Task 3: Migrasi + model + ReportBotGate

**Files:**
- Create: `database/migrations/2026_01_01_000073_create_report_bot_tables.php`
- Create: `app/Models/TelegramBotChat.php`, `app/Models/TelegramBotPendingFile.php`
- Create: `app/Services/ReportBot/ReportBotGate.php`
- Test: `tests/Feature/ReportBot/ReportBotGateTest.php`

**Interfaces:**
- Produces: tabel `telegram_bot_chats` (`id`, `chat_id` unique, `name` nullable, `authorized_at` nullable, `last_used_at` nullable, `is_blocked` bool default false, timestamps) & `telegram_bot_pending_files` (`id`, `chat_id` index, `kind` [csv/xlsx], `path`, `created_at`).
- `ReportBotGate::check(string|int $chatId, ?string $name, string $text): string` → salah satu: `active`, `authorized_now`, `need_code`, `wrong_code`, `blocked`. Kode akses di `AppSetting::get('report_bot_access_code')`.

- [ ] **Step 1: Failing test**
```php
public function test_gerbang_kode_akses(): void
{
    AppSetting::put('report_bot_access_code', 'BUKA123');
    $g = app(ReportBotGate::class);
    $this->assertSame('need_code', $g->check(1, 'A', 'halo'));      // belum aktif, bukan kode
    $this->assertSame('wrong_code', $g->check(1, 'A', 'salah'));
    $this->assertSame('authorized_now', $g->check(1, 'A', 'BUKA123'));
    $this->assertSame('active', $g->check(1, 'A', 'apa saja'));     // sudah aktif
    TelegramBotChat::where('chat_id', 1)->update(['is_blocked' => true]);
    $this->assertSame('blocked', $g->check(1, 'A', 'apa saja'));
}
```
- [ ] **Step 2: Run → fail**
- [ ] **Step 3: Implement** — migrasi + model + gate: cari chat; kalau blocked→`blocked`; kalau authorized_at≠null→update last_used_at, `active`; else kalau `trim($text)===kode`→create/update authorized_at=now, `authorized_now`; else kalau ada kode & belum aktif→`wrong_code` bila text tak kosong dan bukan perintah, `need_code` bila belum pernah. (Sederhana: kalau text sama kode→authorized_now; kalau chat belum ada→need_code+catat; kalau ada tapi belum aktif & text≠kode→wrong_code.)
- [ ] **Step 4: Run → pass**
- [ ] **Step 5: Commit** `feat(report-bot): migrasi 000073 + gerbang kode akses per-chat`

### Task 4: Kontrol admin di Pengaturan Sistem

**Files:**
- Create: `app/Http/Controllers/ReportBotAdminController.php`
- Modify: `routes/web.php` (grup `permission:system_settings`)
- Create: `resources/views/report_bot/_admin.blade.php`
- Modify: `resources/views/settings/index.blade.php` (`@include('report_bot._admin')`)
- Test: `tests/Feature/ReportBot/ReportBotAdminTest.php`

**Interfaces:**
- Produces: `POST /settings/report-bot/rotate` (name `report-bot.rotate`) → set `AppSetting report_bot_access_code` ke kode acak (`strtoupper(Str::random(8))`); `POST /settings/report-bot/chats/{chat}/revoke` (name `report-bot.chat.revoke`) → `is_blocked=true`.

- [ ] **Step 1: Failing test** — super_admin POST rotate → redirect, `AppSetting::get('report_bot_access_code')` berubah & tidak kosong; POST revoke chat → chat `is_blocked` true; user tanpa `system_settings` → 403.
- [ ] **Step 2: Run → fail**
- [ ] **Step 3: Implement** — controller 2 method + rute di grup `system_settings` (baris ~399) + section Blade (tampilkan kode saat ini, tombol rotate, daftar chat aktif + tombol cabut).
- [ ] **Step 4: Run → pass**
- [ ] **Step 5: Commit** `feat(report-bot): kontrol admin kode akses + cabut chat di Pengaturan`

### Task 5: ReportBotRouter

**Files:**
- Create: `app/Services/ReportBot/ReportBotRouter.php`
- Test: `tests/Unit/ReportBot/ReportBotRouterTest.php`

**Interfaces:**
- Produces: `ReportBotRouter::detect(string $fileName, string $mime): ?string` → `leads` (nama mengandung "leads"), `ads` (nama mengandung "ad"), `tiktok_income` (ekstensi csv/xlsx & bukan leads/ads), else `null`.

- [ ] **Step 1: Failing test** — `('Rave leads 1-16 Mar.pdf','application/pdf')`→`leads`; `('5. Rave Tailor Mei Report Ad.xlsx.pdf','application/pdf')`→`ads`; `('Semua pesanan.csv','text/csv')`→`tiktok_income`; `('income.xlsx', ...spreadsheet)`→`tiktok_income`; `('random.pdf','application/pdf')`→`null`.
- [ ] **Step 2: Run → fail**
- [ ] **Step 3: Implement** — cocokkan lowercase nama file; urutan cek: leads → ads → (csv|xlsx)→tiktok_income → null.
- [ ] **Step 4: Run → pass**
- [ ] **Step 5: Commit** `feat(report-bot): router deteksi flow dari nama file & tipe`

### Task 6: PdfTextExtractor (PHP murni)

**Files:**
- Create: `app/Support/PdfTextExtractor.php`
- Create: `tests/fixtures/report_bot/creator_list.pdf` (salin dari sample Creator List)
- Test: `tests/Unit/PdfTextExtractorTest.php`

**Interfaces:**
- Produces: `PdfTextExtractor::extract(string $path): string`; `PdfTextExtractor::looksUnreadable(string $text): bool` (true bila kosong ATAU rasio karakter non-printable tinggi → sinyal perlu fallback AI).

- [ ] **Step 1: Failing test**
```php
public function test_ekstrak_pdf_rapi(): void
{
    $t = PdfTextExtractor::extract(base_path('tests/fixtures/report_bot/creator_list.pdf'));
    $this->assertStringContainsString('Creator name', $t);
    $this->assertStringContainsString('GMV', $t);
    $this->assertFalse(PdfTextExtractor::looksUnreadable($t));
}
public function test_deteksi_teks_tak_terbaca(): void
{
    $this->assertTrue(PdfTextExtractor::looksUnreadable(''));
    $this->assertTrue(PdfTextExtractor::looksUnreadable("\x01\x02\x03\xff\xfe garble"));
}
```
- [ ] **Step 2: Run → fail**
- [ ] **Step 3: Implement** — port `scratchpad/pdftext.php` (regex stream→gzuncompress/gzinflate→ekstrak `(...)Tj` & `[...]TJ`); `looksUnreadable`: true bila `strlen(trim)===0` atau `preg_match_all('/[^\P{C}]/u')`-rasio > 0.3.
- [ ] **Step 4: Run → pass**
- [ ] **Step 5: Commit** `feat(report-bot): PdfTextExtractor PHP murni + deteksi teks tak terbaca`

### Task 7: ReportAi (multimodal + JSON)

**Files:**
- Modify: `app/Services/Ai/OpenAiProvider.php` (mapMessages: konten array diteruskan apa adanya)
- Create: `app/Services/ReportBot/ReportAi.php`
- Test: `tests/Feature/ReportBot/ReportAiTest.php`

**Interfaces:**
- Produces: `ReportAi::readFile(string $bytes, string $mime, string $instruction): array` (kirim ke provider sebagai konten multimodal `[['type'=>'text','text'=>$instruction],['type'=>'file','file'=>['filename'=>'doc','file_data'=>'data:'.$mime.';base64,'.base64_encode($bytes)]]]`, balikin JSON ter-decode); `ReportAi::analyze(string $systemPrompt, array $json): string` (chat system+user JSON → teks).
- Consumes: `AiProviderFactory::make()`, `AiProvider::chat`, `AiTurn::$text`.

- [ ] **Step 1: Failing test** — `Http::fake` OpenAI mengembalikan `choices.0.message.content` = `'{"ok":true}'`; `readFile('x','application/pdf','ambil data')` → `['ok'=>true]`; assert request `messages.0.content` berupa array & mengandung part `file`.
- [ ] **Step 2: Run → fail**
- [ ] **Step 3: Implement** — di `OpenAiProvider::mapMessages`, cabang system/user: `'content' => is_array($m['content'] ?? null) ? $m['content'] : (string) ($m['content'] ?? '')`. `ReportAi`: bangun messages, panggil `AiProviderFactory::make()->chat($messages, [])`, `json_decode` dengan strip fence ```json.
- [ ] **Step 4: Run → pass**
- [ ] **Step 5: Commit** `feat(report-bot): ReportAi wrapper multimodal + dukungan konten array di OpenAiProvider`

### Task 8: Dispatcher + rangkai webhook (ack cepat)

**Files:**
- Create: `app/Services/ReportBot/ReportBotDispatcher.php`
- Modify: `app/Http/Controllers/TelegramWebhookController.php`
- Test: `tests/Feature/ReportBot/DispatcherTest.php`

**Interfaces:**
- Produces: `ReportBotDispatcher::handle(array $update): void` — ambil `message.chat.id`, `message.from`, `message.text`, `message.document`; jalankan gate; kalau `need_code/wrong_code`→`sendMessage` minta kode; kalau `blocked`→abaikan; kalau `active/authorized_now` & ada document→`ReportBotRouter::detect` lalu panggil flow (Fase 2-4; sementara stub yang `sendMessage` "flow X belum aktif").
- Consumes: `ReportBotGate`, `ReportBotRouter`, `TelegramClient`.

- [ ] **Step 1: Failing test** — chat aktif kirim dokumen `leads.pdf` → `TelegramClient` (fake/mock) menerima pemanggilan flow leads; chat baru kirim 'halo' → `sendMessage` berisi permintaan kode. (Mock `TelegramClient` via container binding + `Http::fake`.)
- [ ] **Step 2: Run → fail**
- [ ] **Step 3: Implement** — dispatcher + controller `handle`: verifikasi secret (Task 1) → `if (function_exists('fastcgi_finish_request')) { response()->noContent()->send(); fastcgi_finish_request(); }` lalu `app(ReportBotDispatcher::class)->handle($request->all())`; bungkus dispatcher dalam try/catch + `Log::error` supaya selalu 200 ke Telegram.
- [ ] **Step 4: Run → pass** + jalankan seluruh suite `C:\php83\php.exe artisan test`.
- [ ] **Step 5: Commit** `feat(report-bot): dispatcher webhook + ack cepat + stub flow`

**Validasi manual (spike, di luar test):** dengan `TELEGRAM_BOT_TOKEN` + model multimodal terisi di `.env`, jalankan `ReportAi::readFile()` atas `Rave leads 1-16 Mar.pdf` & `…Report Ad….pdf`; pastikan JSON angka masuk akal. Bila kualitas kurang → naikkan model / minta `.xlsx` (Ads).

---

## FASE 2 — Flow Leads (task-level)

### Task 9: LeadsReportFlow
**Files:** Create `app/Services/ReportBot/Flows/LeadsReportFlow.php`, `resources/views/report_bot/leads.blade.php`; Test `tests/Feature/ReportBot/LeadsFlowTest.php`.
**Alur:** unduh PDF (`TelegramClient`) → `PdfTextExtractor::extract`; bila `looksUnreadable` → `ReportAi::readFile` (multimodal) untuk dapat JSON per-CS `{csName, dailyRows:[{date,leads,closing,rate,omzet,...}], total:{...}}`.
- **Agregasi di PHP** (port `Code in parse` dari n8n): urutkan `dailyRows` per tanggal, ambil H-1 (terakhir), susun `period {from,to,leads,closing,rate,omzet,...}` dari `total`.
- **Analisis AI**: `ReportAi::analyze($systemPromptLeads, $payload)` — `$systemPromptLeads` **di-port verbatim** dari node "AI Daily Report Analyzer" (`scratchpad/n8n_full.txt`, mulai baris ~84; termasuk business rule: target CS Rp2.800.000, closing ≥10%, pengecualian Bobby/Alfin/Surya/Danu Rp3.500.000 & ≥25%, bulanan >Rp85jt / >Rp100jt).
- **Render**: gabung output semua CS ke satu file HTML (`resources/views/report_bot/leads.blade.php`, port `Code in Generate HTML`) → `TelegramClient::sendDocument($chatId, 'Laporan Leads.html', $html)`.
**Tests:** dengan `ReportAi` di-fake (kembalikan JSON contoh 2 CS + teks analisis), assert `sendDocument` dipanggil dengan HTML memuat nama CS & bagian "LAPORAN SUMMARY".
**Commit:** `feat(report-bot): flow Leads (ekstraksi→agregasi PHP→AI→HTML)`

## FASE 3 — Flow Ads (task-level)

### Task 10: AdsReportFlow
**Files:** Create `app/Services/ReportBot/Flows/AdsReportFlow.php`, `resources/views/report_bot/ads.blade.php`; Test `tests/Feature/ReportBot/AdsFlowTest.php`.
**Alur:** bila file `.xlsx` → `SpreadsheetReader` → array baris; bila `.pdf` → `PdfTextExtractor` lalu (bila `looksUnreadable`) `ReportAi::readFile`. → JSON metrik iklan.
- **Analisis AI**: `ReportAi::analyze($systemPromptAds, $json)` — prompt **di-port verbatim** dari node "AI Daily ADS Analyzer" (`scratchpad/n8n_full.txt`, baris ~585).
- **Render**: `resources/views/report_bot/ads.blade.php` → `sendDocument($chatId, 'Laporan Ads.html', $html)`.
**Tests:** `.xlsx` fixture → `SpreadsheetReader` menghasilkan baris; `ReportAi` fake → `sendDocument` dgn HTML analisis. Assert jalur `.xlsx` TIDAK memanggil AI multimodal (lebih murah).
**Commit:** `feat(report-bot): flow Ads (xlsx utama / PDF cadangan → AI → HTML)`

## FASE 4 — Flow TikTok Income (task-level, REUSE)

### Task 11: Refactor penyusunan xlsx ke service
**Files:** Modify `app/Services/TikTokIncomeReportService.php` (tambah `toXlsxSheets(array $report): array` mengembalikan `['Income'=>['headers'=>[...],'rows'=>[...]]]`), `app/Http/Controllers/TikTokIncomeController.php` (`download` panggil `XlsxWriter::download('Laporan Income TikTok.xlsx', $this->service->toXlsxSheets($report))`).
**Tests:** `tests/Feature/TikTokIncomeTest.php` (yang ada) tetap hijau; tambah unit `toXlsxSheets` menghasilkan header `['Order ID','Waktu','Type','Total Pendapatan','Total Biaya','Settlement', ...columns]`.
**Commit:** `refactor(tiktok): pindah penyusunan xlsx income ke service (dipakai web + bot)`

### Task 12: TikTokIncomeFlow (bot)
**Files:** Create `app/Services/ReportBot/Flows/TikTokIncomeFlow.php`; Test `tests/Feature/ReportBot/TikTokIncomeFlowTest.php`.
**Alur:** unduh file → simpan sementara + catat `telegram_bot_pending_files` (kind csv/xlsx) per chat. Bila pasangan (csv **dan** xlsx) sudah ada → `TikTokIncomeReportService::fromFiles($csvPath, $xlsxPath)` → `toXlsxSheets` → `XlsxWriter::toString(...)` (atau tulis file temp) → `TelegramClient::sendDocument($chatId,'Laporan Income TikTok.xlsx',$bytes)`; hapus pending + file temp. Bila baru satu → `sendMessage` "sudah terima {kind}, kirim file satunya (csv/xlsx)".
**Tests:** kirim `.csv` → balasan "kirim xlsx"; lalu kirim `.xlsx` → `sendDocument` xlsx (reuse service, tanpa AI). Pakai fixture csv+xlsx kecil.
**Commit:** `feat(report-bot): flow TikTok Income via bot (reuse TikTokIncomeReportService)`

### Task 13: Sambungkan flow ke dispatcher + suite hijau
**Files:** Modify `app/Services/ReportBot/ReportBotDispatcher.php` (ganti stub → panggil `LeadsReportFlow/AdsReportFlow/TikTokIncomeFlow`).
**Tests:** integrasi: chat aktif kirim `leads.pdf`/`ads.xlsx`/`pesanan.csv` → flow terkait terpanggil. Jalankan `C:\php83\php.exe artisan test` (seluruh suite hijau) + `pint --dirty`.
**Commit:** `feat(report-bot): sambungkan 3 flow ke dispatcher`

---

## Self-Review
- **Spec coverage:** webhook+secret (T1), gate kode akses+admin (T3,T4), router (T5), PdfTextExtractor+fallback (T6, dipakai T9/T10), ReportAi multimodal+JSON (T7), Leads (T9), Ads xlsx-utama (T10), TikTok Income reuse (T11,T12), ack cepat (T8), tanpa menu (hanya webhook + section Pengaturan), zero-dep (tak ada package). FS & Creator List sengaja tak ada task. ✔
- **Prompt verbatim:** T9/T10 menunjuk lokasi sumber persis (n8n_full.txt) — disalin saat implementasi, bukan placeholder.
- **Konsistensi tipe:** `TelegramClient` (T2) dipakai T8/T9/T10/T12; `ReportAi::readFile/analyze` (T7) dipakai T9/T10; `PdfTextExtractor::extract/looksUnreadable` (T6) dipakai T9/T10; `ReportBotGate::check`→string status (T3) dipakai T8; `TikTokIncomeReportService::fromFiles/toXlsxSheets` (T11) dipakai T12. ✔
- **Prasyarat manual** (rotate token, set webhook+secret, model multimodal) ada di Global Constraints + validasi manual Fase 1.
