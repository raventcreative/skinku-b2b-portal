# Report Bot Telegram (Migrasi n8n → SKINKU) — Design Spec

**Tanggal:** 2026-08-07
**Status:** Design — menunggu review Freddie sebelum writing-plans
**Sumber:** n8n workflow export "Daily Report FS(fix), Leads + Ads (Updgraded)" (56 node). Ekstraksi lengkap disimpan di scratchpad (`n8n_full.txt`) + file JSON asli.

## Tujuan
Memindahkan workflow n8n (bot Telegram penganalisis laporan harian) ke dalam SKINKU sebagai kode Laravel, supaya **tidak perlu langganan n8n**, jalan di infra yang sudah ada, **tanpa menu fitur yang kelihatan** (murni background/bot).

## Konteks bisnis (apa yang bot ini lakukan)
Tim mengirim **file laporan ke bot Telegram**; bot mengunduh file, menganalisis dengan AI, membalas **file report HTML** (+ untuk Income: file xlsx). Tiga alur, dipilih dari **kata kunci di nama file**:
- **Leads** — laporan harian CS/sales (leads, closing, closing rate, omzet per orang).
- **Ads** — laporan performa iklan.
- **FS** — Financial Statement (laporan keuangan, dengan KPI + kaidah PSAK).
- (Jalur **Income** Excel & **CSV order** di workflow n8n = fitur yang **SUDAH dimigrasi** ke SKINKU sebagai `TikTokIncomeController` / `/tiktok/income`. **Tidak dibangun ulang** — lihat "Di luar cakupan".)

## Keputusan yang sudah dikunci (hasil brainstorm)
1. **Rebuild di SKINKU** (Laravel, zero-dependency), bukan self-host n8n.
2. **Tanpa arsip** — Google Drive dibuang total. Output cukup dibalas via Telegram.
3. **AI numpang infra SKINKU** — `AiProviderFactory` (OpenAI-compatible). Sudah mendukung **OpenRouter** (base URL configurable; bahkan default backup = OpenRouter → bisa pilih model Gemini/GPT). Tidak ada kredensial AI baru selain key yang sudah/akan diisi.
4. **Gerbang 1 kode akses bersama** — bot menolak chat yang belum "login". Kirim kode sekali → chat ditandai aktif → seterusnya bebas. Kode dikelola dari kontrol admin kecil (bukan menu fitur).
5. **Tanpa menu fitur** — hanya endpoint webhook + kontrol admin kecil di Pengaturan Sistem.

## ⚠️ Keamanan (WAJIB sebelum mulai)
- File JSON n8n memuat **token bot Telegram yang masih aktif**. **Rotate/ganti token** di BotFather sebelum apa pun. Token baru masuk `.env` (`TELEGRAM_BOT_TOKEN`), tidak pernah di kode.
- Webhook diverifikasi pakai **Telegram secret token** (header `X-Telegram-Bot-Api-Secret-Token`) → hanya Telegram yang boleh memicu endpoint. Secret di `.env`.
- Gerbang kode akses melindungi kredit AI dari penyalahgunaan.

## 🔴 Titik teknis paling berisiko: ekstraksi PDF (koreksi dari diskusi awal)
Sebelumnya aku bilang "PDF bisa langsung ke AI jadi hurdle-nya hilang". Itu **kurang tepat** — dan penting diluruskan:

Filosofi workflow ini adalah **angka di-parse deterministik dulu**, lalu AI **hanya menarasikan** (prompt-nya keras: *"do NOT invent numbers, use ONLY provided input"*). Kalau PDF langsung dilempar ke AI untuk mengambil angka, kita melanggar filosofi itu dan berisiko AI salah baca angka keuangan.

Jadi untuk **setia** ke desain asli, kita tetap butuh **PDF → teks** lebih dulu, baru parser deterministik jalan. Opsi (diurutkan rekomendasi):

- **A (rekomendasi): Minimal `PdfTextExtractor` PHP** — sesuai gaya SKINKU (`SpreadsheetReader`/`XlsxWriter` yang sudah ada). PDF report itu **berbasis teks** (bukan scan), jadi bisa dibaca dengan parser stream + `gzuncompress` (zlib **bawaan PHP**, tanpa package). Cukup untuk PDF generated; rapuh untuk PDF aneh/scan.
- **B (fallback): AI multimodal sebagai OCR** — kalau extractor gagal pada file tertentu, kirim PDF ke model multimodal HANYA untuk mengubah jadi teks/tabel, lalu parser deterministik tetap jalan atas teks itu.
- **C: Dorong input Excel/CSV** — jika sumber datanya memang dari spreadsheet, minta kirim Excel/CSV (lebih andal dari PDF). Jalur Income sudah begini.

**De-risk lebih dulu:** butuh **contoh file PDF asli** (Leads/Ads/FS) dari Freddie untuk memvalidasi extractor di Fase 1 sebelum lanjut. Asumsi `zlib` aktif di server (standar — perlu dicek).

## Arsitektur

```
Telegram  --POST-->  /telegram/webhook  (TelegramWebhookController)
                          |
              verifikasi secret token
                          |
                 GerbangKodeAkses  (chat aktif? kalau belum → minta kode)
                          |
                  Router (nama file mengandung leads/ads/fs? mime pdf/spreadsheet/csv?)
                    |         |          |            |
                  Leads     Ads         FS         Income/CSV
                    |         |          |            |
         (unduh file → ekstrak → transform deterministik → AI narasi → generate HTML/xlsx)
                          |
                 kirim balik ke Telegram (sendDocument / sendMessage)
```

Komponen (unit fokus, satu tanggung jawab):
- **`TelegramWebhookController`** — terima update, verifikasi secret, delegasi.
- **`TelegramClient`** (service) — `getFile`, `downloadFile`, `sendMessage`, `sendDocument` (HTTP via Laravel `Http`, token dari config).
- **`ReportBotGate`** — cek/otorisasi chat via kode akses; simpan chat aktif.
- **`ReportBotRouter`** — tentukan flow dari nama file + mime.
- **`PdfTextExtractor`** — minimal, zero-dep (lihat risiko di atas).
- **Flow handlers**: `LeadsReportFlow`, `AdsReportFlow`, `FsReportFlow`, `IncomeFlow` — masing-masing: extract → transform → AI → render.
- **`ReportAi`** — wrapper tipis di atas `AiProviderFactory` untuk panggilan report (system prompt + JSON input → teks/terstruktur). Tambah dukungan lampiran file untuk fallback multimodal.
- **View Blade** untuk tiap report HTML (ganti "Code in Generate HTML" n8n).

## Model data (minimal)
Migrasi baru (nomor berikutnya yang bebas, mis. `2026_01_01_000073` — pastikan unik saat implementasi):
- **`telegram_bot_chats`**: `id`, `chat_id` (unik), `name` (nullable), `authorized_at` (nullable), `last_used_at` (nullable), `is_blocked` (bool, default false).
- **Kode akses** disimpan di `AppSetting` (`report_bot_access_code`) — bisa di-rotate dari kontrol admin. (Kode kecil, low-sensitivity; disimpan agar admin bisa lihat & bagikan.)
- **(Opsional, disarankan) `report_bot_logs`**: `chat_id`, `flow`, `file_name`, `status`, `error`, `created_at` — untuk audit & debug biaya AI. Bisa masuk fase belakangan.

## Detail per-flow
Prompt AI & rumus di-**port verbatim** dari sumber (disalin utuh saat writing-plans). Ringkasan di sini:

### Leads (`nama file mengandung "leads"`, PDF)
- Parse teks PDF jadi blok per-CS (baris tanggal + baris total). Port `Code Parse & Split` + `Code in parse` (H-1 harian + agregat periode).
- AI (`AI Daily Report Analyzer`) → ringkasan per orang + summary periode, Bahasa Indonesia, tanpa mengarang angka.
- **Business rule tertanam di prompt (perlu Freddie konfirmasi/masih berlaku?):** target harian CS Rp 2.800.000; closing rate sehat ≥10%; **pengecualian nama Bobby/Alfin/Surya/Danu** → target Rp 3.500.000 & closing rate ≥25%; target bulanan >Rp 85.000.000 (pengecualian nama di atas >Rp 100.000.000).
- Render 1 file HTML berisi semua CS → `sendDocument`.

### Ads (`"ads"`, PDF)
- Parse teks PDF Ads (`Code Parse & Split Ads`) → AI (`AI Daily ADS Analyzer`) → HTML → `sendDocument`.

### FS — Financial Statement (`"fs"`, PDF) — TERBERAT
Pipeline komputasi keuangan sebelum AI (port berurutan):
`Code Parse Exctract Data` → `Code Parse Normalize` → `Working Capital KPI` → `PSAK Rule Engine` → `Code in KPI FS` → `Contributor Share` → `AI Daily FS Analyzer` (analis keuangan; pakai angka dari JSON apa adanya; definisi ketat: revenue/hpp/operating_expense/operating_income/gross_profit/net_income + breakdown salary/marketing/others + cabang Surabaya/Jakarta untuk pembanding) → HTML → `sendDocument`.
- Ini praktis mesin analisis keuangan mini — layak jadi fase tersendiri.

### Income (Excel) & CSV — SUDAH ADA (tidak dibangun ulang)
Identik dengan fitur yang **sudah dimigrasi** ke SKINKU: `TikTokIncomeController` (`/tiktok/income`) — upload CSV pesanan + xlsx income → gabung → unduh xlsx. Jadi **di luar cakupan bot ini**.
- **Opsional (kalau diminta):** tambah pintu di bot Telegram yang **memanggil ulang logika `TikTokIncomeController` yang sudah ada** (bukan menulis ulang) supaya bisa lewat chat juga. Belum diputuskan.

## Zero-dependency — pemetaan kemampuan
| Kebutuhan | Cara (tanpa package) |
|---|---|
| Terima/kirim Telegram + panggil AI | Laravel `Http` |
| Baca PDF | `PdfTextExtractor` minimal (zlib bawaan) — lihat risiko |
| Baca Excel/CSV | `SpreadsheetReader` yang sudah ada |
| Report HTML | Blade |
| Report xlsx | `XlsxWriter` (hanya bila opsi pintu bot Income diaktifkan; Leads/Ads/FS keluar HTML) |
| AI | `AiProviderFactory` yang sudah ada (+ dukungan lampiran file) |

## Rencana bertahap (tiap fase = software jalan & teruji)
- **Fase 1 — Plumbing + de-risk PDF:** webhook + verifikasi secret; `TelegramClient`; `ReportBotGate` (migrasi `telegram_bot_chats` + kode akses di AppSetting + kontrol admin rotate/lihat/cabut di Pengaturan Sistem); `ReportBotRouter` (deteksi flow); **`PdfTextExtractor` divalidasi ke PDF contoh asli**. *Deliverable:* bot minta kode → aktif → mengenali jenis file → bisa ekstrak teks PDF nyata.
- **Fase 2 — Flow Leads** end-to-end (parser + prompt + HTML).
- **Fase 3 — Flow Ads.**
- **Fase 4 — Flow FS** (pipeline keuangan + PSAK + prompt + HTML).
- *(Income Excel + CSV: DIHAPUS dari rencana — sudah ada di `/tiktok/income`. Kalau mau pintu bot-nya, jadi fase opsional yang memanggil ulang `TikTokIncomeController`.)*

## Rencana tes
- **Unit**: `ReportBotRouter` (pemetaan nama file/mime → flow); `ReportBotGate` (kode salah/benar, chat aktif tak perlu kode lagi, blokir); `PdfTextExtractor` (fixture PDF teks → string benar); parser per flow (fixture teks → JSON angka benar); rumus FS (Working Capital/PSAK/Contributor/KPI dengan angka contoh).
- **Feature**: POST webhook tanpa secret → 403; chat belum login → dibalas minta kode; kirim kode benar → aktif; kirim file "leads/ads/fs" → memanggil flow yang benar & membalas dokumen (Telegram di-`Http::fake`); AI di-fake.
- **Regresi**: infra AI lama (asisten & OKR) tak terpengaruh oleh penambahan lampiran file.

## Prasyarat / setup (dikerjakan Freddie saat deploy)
- **Rotate token bot** di BotFather → `TELEGRAM_BOT_TOKEN` di `.env`.
- Set `TELEGRAM_WEBHOOK_SECRET` di `.env`; daftarkan webhook ke `https://system.skinku.id/telegram/webhook` dengan secret (satu kali, via artisan command yang kita sediakan).
- Pastikan model AI yang dipilih **multimodal** hanya jika pakai fallback B; untuk jalur utama (extractor) tidak wajib.
- **Kirim contoh file PDF asli** (Leads/Ads/FS) untuk validasi extractor.
- `zlib` aktif di server.

## Di luar cakupan (sengaja)
- Jalur **Income (Excel) & CSV order** — sudah dimigrasi sebelumnya ke `TikTokIncomeController` (`/tiktok/income`); **tidak dibangun ulang**. (Opsional nanti: pintu bot Telegram yang memanggil ulang logika itu — bukan menulis ulang.)
- Arsip ke Google Drive (dibuang).
- Kode akses per-orang (pakai 1 kode bersama).
- Penjadwalan cron (bot on-demand via Telegram, bukan terjadwal — walau namanya "Daily").
- Menyimpan hasil report ke database SKINKU / dashboard (tidak diminta).

## Catatan terbuka
- Konfirmasi angka business rule Leads (target & nama pengecualian) masih berlaku.
- Perlu contoh PDF asli untuk memutuskan A vs B pada ekstraksi PDF.
- Income TikTok: cukup tetap lewat form web `/tiktok/income` yang sudah ada, atau mau ditambah pintu bot Telegram (panggil ulang `TikTokIncomeController`)?
