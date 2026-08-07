# Report Bot Telegram (Migrasi n8n → SKINKU) — Design Spec

**Tanggal:** 2026-08-07 (diperbarui setelah validasi 3 file sample)
**Status:** Design — menunggu review Freddie sebelum writing-plans
**Sumber:** n8n workflow export "Daily Report FS(fix), Leads + Ads (Updgraded)" (56 node) + 3 file sample nyata.

## Tujuan
Memindahkan workflow n8n (bot Telegram pengolah laporan harian) ke dalam SKINKU sebagai kode Laravel, supaya **tidak perlu langganan n8n**, jalan di infra yang ada, **tanpa menu fitur yang kelihatan** (murni background/bot).

## Cakupan final (3 flow)
Kirim file ke bot Telegram; jenis flow dipilih dari **kata kunci nama file** + tipe file:
1. **Leads** — laporan harian CS/sales (leads, closing, closing rate, omzet per orang) → **AI analisis** → report HTML.
2. **Ads** — laporan performa iklan → **AI analisis** → report HTML.
3. **TikTok Income** — gabung "Semua pesanan" (.csv) + income (.xlsx) join by Order ID → xlsx. **Pakai ulang `TikTokIncomeReportService` yang sudah ada** (bukan AI, bukan nulis ulang).

**Di-drop / diparkir:**
- **FS (Financial Statement)** — **di-drop** (belum ada sample; garap nanti kalau perlu, lihat "Di luar cakupan").
- **Creator List (analisis creator TikTok)** — file sample sempat dikirim tapi **bukan** yang dimaksud "tiktok"; **diparkir** (bisa jadi flow terpisah kalau nanti diminta).

## Keputusan yang dikunci
1. **Rebuild di SKINKU** (Laravel, zero-dependency).
2. **Tanpa arsip** — Google Drive dibuang. Output dibalas via Telegram.
3. **AI numpang `AiProviderFactory`** (OpenAI-compatible; OpenRouter didukung → bisa pilih Gemini/GPT). **Model WAJIB multimodal** (lihat ekstraksi PDF).
4. **Gerbang 1 kode akses bersama** — chat harus "login" sekali (kirim kode) → aktif → seterusnya bebas. Dikelola dari kontrol admin kecil (bukan menu fitur).
5. **Tanpa menu fitur** — hanya webhook + kontrol admin kecil.

## ⚠️ Keamanan (WAJIB sebelum mulai)
- File JSON n8n memuat **token bot Telegram aktif** → **rotate di BotFather**, token baru ke `.env` (`TELEGRAM_BOT_TOKEN`), tak pernah di kode.
- Webhook diverifikasi **Telegram secret token** (header `X-Telegram-Bot-Api-Secret-Token`) di `.env`.
- Gerbang kode akses melindungi kredit AI.

## 🔬 Ekstraksi PDF — SUDAH DIVALIDASI (bukan lagi risiko terbuka)
Aku tes extractor PHP murni (FlateDecode via zlib) ke 3 file nyata:

| File | Hasil ekstraksi PHP murni | Sebab |
|---|---|---|
| Leads (`Rave leads…`) | ❌ **0 teks** | font **CID/Type0** 2-byte, ToUnicode minim |
| Ads (`…Report Ad…`) | ❌ **teks sampah** | font CID + **3 gambar**; sebenarnya **Excel→PDF** |
| Creator List | ✅ **bersih** (tabel rapi) | font biasa, tanpa CID |

**Kesimpulan → strategi HIBRIDA:**
- **Coba `PdfTextExtractor` PHP murni dulu** (gratis) — cukup untuk PDF rapi & semua Excel/CSV.
- **Kalau hasil kosong/sampah → fallback AI multimodal** yang membaca PDF langsung (Leads & Ads butuh ini). **Karena itu model AI wajib multimodal** (gpt-4o / gemini-flash via OpenRouter).
- **Ads: minta kirim `.xlsx` aslinya** (sumbernya Excel) → dibaca `SpreadsheetReader`, paling andal & murah. PDF = jalur cadangan (multimodal).
- **Reliabilitas angka:** AI hanya **mengekstrak ke JSON terstruktur**; tak ada hitungan yang dikarang AI (untuk Leads/Ads, agregasi dilakukan di PHP dari angka hasil ekstraksi).
- Deteksi "hasil jelek → fallback": rasio karakter non-ASCII tinggi / panjang teks jauh di bawah wajar untuk ukuran file.

## Arsitektur
```
Telegram --POST--> /telegram/webhook (TelegramWebhookController)
   → verifikasi secret token
   → ReportBotGate (chat aktif? kalau belum → minta kode akses)
   → ReportBotRouter (nama file: leads/ads/income? tipe: pdf/xlsx/csv?)
        ├─ Leads  → extract(PHP→AI) → ReportAi analisis → Blade HTML → sendDocument
        ├─ Ads    → extract(xlsx/PHP→AI) → ReportAi analisis → Blade HTML → sendDocument
        └─ TikTok Income → cache .csv per chat; saat .xlsx datang → TikTokIncomeReportService::fromFiles → XlsxWriter → sendDocument
```

Komponen (satu tanggung jawab):
- **`TelegramWebhookController`** — terima update, verifikasi secret, delegasi.
- **`TelegramClient`** — `getFile`/`downloadFile`/`sendMessage`/`sendDocument` (Laravel `Http`, token dari config).
- **`ReportBotGate`** — cek/otorisasi chat via kode akses; simpan chat aktif.
- **`ReportBotRouter`** — tentukan flow dari nama file + mime.
- **`PdfTextExtractor`** — PHP murni (zlib); dipakai untuk PDF rapi & jadi lapis pertama.
- **`ReportAi`** — wrapper tipis di atas `AiProviderFactory`; **dukungan lampiran file** (multimodal) + structured output JSON.
- **Flow handlers**: `LeadsReportFlow`, `AdsReportFlow`, `TikTokIncomeFlow`.
- **View Blade** untuk report HTML Leads & Ads.
- **Reuse**: `TikTokIncomeReportService` + `XlsxWriter` (sudah ada) untuk flow TikTok Income.

## Model data (minimal)
Migrasi baru (nomor bebas berikutnya, mis. `2026_01_01_000073` — pastikan unik saat implementasi):
- **`telegram_bot_chats`**: `id`, `chat_id` (unik), `name` (nullable), `authorized_at` (nullable), `last_used_at`, `is_blocked` (bool).
- **`telegram_bot_pending_files`** (untuk pairing CSV+XLSX TikTok Income): `chat_id`, `kind` (csv/xlsx), `path`, `created_at`. Atau simpan file sementara di `storage` berkunci chat_id + TTL. (File dihapus setelah dipakai/kadaluarsa.)
- **Kode akses** di `AppSetting` (`report_bot_access_code`) — bisa di-rotate dari kontrol admin.
- **(Opsional) `report_bot_logs`**: `chat_id`, `flow`, `file_name`, `status`, `error`, `created_at` — audit & debug biaya AI.

## Detail per-flow
Prompt AI di-**port verbatim** dari sumber saat writing-plans.

### Leads (`nama file mengandung "leads"`, PDF)
- Ekstraksi: PHP murni gagal (CID) → **multimodal AI** baca PDF → JSON per-CS (tanggal harian + total periode).
- Agregasi H-1 & periode dihitung **di PHP** dari JSON (bukan AI).
- AI (`AI Daily Report Analyzer`) → ringkasan per orang + summary periode (Bahasa Indonesia, tanpa mengarang angka).
- **Business rule dari prompt (perlu Freddie konfirmasi masih berlaku):** target CS Rp 2.800.000/hari; closing rate sehat ≥10%; **pengecualian Bobby/Alfin/Surya/Danu** → target Rp 3.500.000 & ≥25%; target bulanan >Rp 85.000.000 (pengecualian >Rp 100.000.000).
- Render 1 file HTML semua CS → `sendDocument`.

### Ads (`"ads"`, utamakan `.xlsx`; PDF = cadangan)
- **Utama:** kirim `.xlsx` → `SpreadsheetReader` → JSON.
- **Cadangan:** PDF → multimodal AI → JSON.
- AI (`AI Daily ADS Analyzer`) → analisis → HTML → `sendDocument`.

### TikTok Income (`.csv` + `.xlsx`) — REUSE
- User kirim **`.csv` "Semua pesanan"** → bot **cache** per chat.
- User kirim **`.xlsx` income** → bot panggil **`TikTokIncomeReportService::fromFiles(csvPath, xlsxPath)`** (join by Order ID) → susun xlsx via **`XlsxWriter`** → `sendDocument`.
- **Refactor kecil:** pindahkan penyusunan baris/kolom xlsx dari `TikTokIncomeController::download()` ke service/helper agar dipakai bersama web + bot. Web `/tiktok/income` tetap ada.
- Tanpa AI.

## Zero-dependency — pemetaan
| Kebutuhan | Cara (tanpa package) |
|---|---|
| Terima/kirim Telegram + panggil AI | Laravel `Http` |
| Baca PDF rapi | `PdfTextExtractor` (zlib bawaan) |
| Baca PDF CID/gambar | **AI multimodal** (lapis kedua) |
| Baca Excel/CSV | `SpreadsheetReader` yang ada |
| Report HTML (Leads/Ads) | Blade |
| Report xlsx (TikTok Income) | `XlsxWriter` yang ada |
| Join income TikTok | `TikTokIncomeReportService` yang ada |

## Rencana bertahap (tiap fase = jalan & teruji)
- **Fase 1 — Plumbing + gate + ekstraksi:** webhook + verifikasi secret; `TelegramClient`; `ReportBotGate` (migrasi `telegram_bot_chats` + kode akses + kontrol admin rotate/lihat/cabut di Pengaturan Sistem); `ReportBotRouter`; `PdfTextExtractor`; `ReportAi` + **spike validasi baca PDF Leads/Ads via multimodal** (pakai sample nyata). *Deliverable:* bot minta kode → aktif → kenali jenis file → bisa dapat teks dari Leads/Ads (via AI) & PDF rapi (via PHP).
- **Fase 2 — Flow Leads** (JSON→agregasi PHP→AI→HTML).
- **Fase 3 — Flow Ads** (xlsx utama / PDF cadangan → AI → HTML).
- **Fase 4 — Flow TikTok Income** (cache CSV + merge XLSX via service reuse → xlsx).

## Rencana tes
- **Unit**: `ReportBotRouter` (nama file/mime → flow); `ReportBotGate` (kode salah/benar, chat aktif tak perlu kode lagi, blokir); `PdfTextExtractor` (fixture PDF rapi → teks); heuristik "hasil jelek → fallback"; agregasi Leads (JSON → angka benar).
- **Feature**: webhook tanpa secret → 403; chat belum login → diminta kode; kode benar → aktif; kirim file "leads/ads" → flow benar & balas dokumen (Telegram+AI di-`Http::fake`); TikTok Income: kirim csv lalu xlsx → balas xlsx (reuse service, tanpa fake AI).
- **Regresi**: `/tiktok/income` web tetap jalan setelah refactor; infra AI lama tak terganggu penambahan lampiran file.

## Prasyarat / setup (Freddie saat deploy)
- **Rotate token bot** → `TELEGRAM_BOT_TOKEN`.
- `TELEGRAM_WEBHOOK_SECRET`; daftarkan webhook `https://system.skinku.id/telegram/webhook` (artisan command yang kita sediakan).
- **Set model AI multimodal** (mis. `google/gemini-flash-1.5` / `gpt-4o-mini` via OpenRouter) di `.env`/Pengaturan.
- (Ads) kebiasaan **kirim `.xlsx`** lebih diutamakan daripada PDF.

## Di luar cakupan (sengaja)
- **FS (Financial Statement)** — di-drop untuk sekarang (belum ada sample). Kalau nanti perlu: flow tambahan dengan pipeline keuangan (normalize → working capital → PSAK → contributor → KPI) yang bisa diport dari n8n; butuh sample PDF/Excel FS.
- **Creator List (analisis creator TikTok)** — diparkir; bisa jadi flow terpisah kalau diminta (ekstraksinya justru mudah — PHP murni sudah bisa baca).
- Arsip Google Drive; kode akses per-orang; cron/terjadwal.

## Catatan terbuka
- Konfirmasi angka business rule Leads (target & nama pengecualian) masih berlaku.
- Fase 1 memvalidasi kualitas baca multimodal untuk Leads/Ads dengan sample nyata sebelum lanjut.
