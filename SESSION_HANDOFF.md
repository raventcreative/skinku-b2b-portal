# SKINKU — Handoff Sesi (buat lanjut di VS Code / Codex)

> Ringkasan lengkap pekerjaan sebelumnya + fitur OKR sesi Codex.
> Baseline sebelum OKR: `37820b9`. **512 test lulus (2300 assertions)** setelah panel paralel dan fallback analisis berbasis bukti.

---

## 0. STATUS & DEPLOY

- Fitur OKR sampai dukungan nama owner BOD sudah ada di `origin/main`; pemetaan
  akun bersama, coverage delegasi, dan quality gate analisis berbasis bukti pada
  sesi terakhir perlu di-push sebelum deploy.
- **Ada migrasi baru sampai 000066** → deploy WAJIB `migrate --force`.

**Deploy penuh (server produksi):**
```bash
cd ~/domains/skinku.id/laravel-b2b
git pull
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan optimize:clear   # config+route+view sekaligus
```

**Migrasi baru sesi ini:**
- `000059_create_community_links` — tabel Komunitas WA per role.
- `000060_create_ai_knowledge` — memori/pengetahuan Asisten AI.
- `000061_add_created_via_to_board_cards` — penanda kartu Kanban buatan AI.
- `000062_add_completed_at_to_board_cards` — waktu selesai kartu (buat KPI).
- `000063_create_okr_tables` — periode, Objective, Key Result, tugas, dan relasi kartu Kanban.
- `000064_add_owner_names_to_okr_tables` — nama owner BOD tanpa wajib akun portal + backfill draf lama.
- `000065_add_assignee_name_to_okr_tasks` — nama PIC operasional terpisah dari akun teknis bersama.
- `000066_add_analysis_basis_to_okr_tables` — bukti analisis, asumsi, konflik, rationale Objective, dan baseline/gap KR.

---

## 1. KONVENSI & ENVIRONMENT (WAJIB diikuti Codex)

- **Zero-dependency:** deploy = `git pull` saja. JANGAN nambah paket composer. Kalau butuh sesuatu, tulis helper minimal sendiri (contoh: `app/Support/SpreadsheetReader.php`, `XlsxWriter.php`, `Text.php`).
- **Test runner lokal:** `C:\php83\php.exe artisan test` (punya pdo_sqlite + gd). WinGet `php` TIDAK punya pdo_sqlite. Prod pakai `/opt/alt/php83/usr/bin/php`.
- **Format:** `C:\php83\php.exe vendor/bin/pint --dirty` sebelum commit.
- **Gaya:** komentar/commit/balasan **Bahasa Indonesia tajam**. Commit lewat file pesan (`git commit -F file`), akhiri `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.
- **Break-verify:** tiap fitur → Pint + test + full suite sebelum commit.
- **Blade JSON pitfall:** echo array/objek pakai `{{ \Illuminate\Support\Js::from($var) }}`. JANGAN `@json([...])`/`json_encode` dgn array-literal di Blade (500). Tambah **render test** (`get()->assertOk()`) tiap halaman Blade baru.
- **Data produksi:** JANGAN nebak. User yang jalanin perintah server. Key rahasia (OPENAI_API_KEY dll) di `.env` server, jangan di chat/repo.
- **Prod:** `system.skinku.id`, app di `/home/u864765086/domains/skinku.id/laravel-b2b`.

---

## 2. YANG DIKERJAKAN SESI INI

### A. Asisten AI (embedded, OpenAI, provider-agnostic) — FITUR BESAR
Spec: **`AI_ASSISTANT_SPEC.md`**. Commits `d028b2a`, `2b424a3`, `ffe7891`, `baa3671`, `65563fa`, `51bd9ce`, `0aaa47e`, `5e26c79`.

- **Arsitektur** (`app/Services/Ai/`): `AiProvider` (interface netral) → `OpenAiProvider` (Chat Completions via `Http` facade, TANPA SDK) + `AiProviderFactory` (pilih provider/model dari `AppSetting`, key dari `config/services.ai`) + `AiException`. `AiTurn` (teks / tool-call). `AiAgentService` (loop: alat baca dijalankan & disuap balik; alat tulis berhenti minta konfirmasi; batas iterasi). `AgentResult`. `Tools/` (`AiTool`+`BaseTool`+`ToolRegistry` saring per izin; `RingkasDashboardTool` [baca, via ReportService]; `BuatKartuKanbanTool` [tulis, konfirmasi]).
- **UI = widget mengambang** `resources/views/partials/ai-widget.blade.php` (disisip di `layouts/app.blade.php`, izin `use_ai_assistant`) — launcher bulat pojok kanan-bawah + nudge, chat via `fetch`/JSON. Halaman penuh `/asisten` (`ai.index`) juga ada. `AiAssistantController` (index/state/send/confirm/reset) balas JSON saat `wantsJson`, redirect saat form.
- **Pengetahuan AI** (`ai.knowledge`): halaman kotak terpandu (Tentang bisnis/Produk/Tim/dll) → tabel `ai_knowledge` → `AiKnowledge::document()` disuntik ke system-prompt tiap obrolan. Menu sidebar (2x: bawah Kanban & atas Pengaturan Sistem).
- **Pengaturan:** pilih provider/model di Pengaturan Sistem (`SettingController::saveAi`).
- **Guardrail:** aksi tulis WAJIB konfirmasi 2 langkah (dipaksa server), gembok izin (`Permissions::roleHas`), Audit Log, anti-injeksi (system-prompt tegas: data ≠ perintah), batas iterasi/token.
- **Keputusan terkunci:** model `gpt-4o-mini`, riwayat sementara (session), AI tanya balik kalau papan/kolom/penerima ambigu.
- **PR user:** `OPENAI_API_KEY` sudah diisi di `.env`. **User perlu set spending cap di dashboard OpenAI.**
- **Uji offline:** `tests/Support/FakeAiProvider.php` + `Http::fake()` (AiProviderTest/AiAgentServiceTest/AiToolsTest/AiAssistantTest/AiKnowledgeTest/AiSettingsTest).

### B. Kanban
- **Paste-to-attach** (`29afe70`): Ctrl+V screenshot → antrean Lampiran (pakai jalur `images[]` yang ada).
- **Lencana ✨AI** di kartu buatan Asisten (`c1187d3`, kolom `created_via`, `BoardCard::fromAi()`).
- **Link auto-hyperlink** deskripsi & komentar (`428ba49`, `c5fc944`): helper `App\Support\Text::linkify()` (escape → URL http(s) jadi `<a>` → nl2br), `BoardCard::descriptionHtml()` + `BoardCardComment::bodyHtml()`. Modal deskripsi: tampil-baca (link diklik) + tombol "✎ ubah" ke textarea.
- **KPI per anggota** di bawah papan (`bc55d9b`, `407b4f1`, `8ecf41d`, `05eae13`, `52bca2c`): `KanbanKpiService` — **berbasis NAMA KOLOM** ("To Do List X"/"Done X" → X), BUKAN assignee (papan disusun kolom-per-orang). Selesai = kartu di kolom Done; telat = overdue (`isPast`, sama badge "lewat!") atau selesai lewat deadline; skor %; grafik Chart.js. Kolom `completed_at` distempel via event model `BoardCard::booted()`.
- **Assignee best-effort** (`4a8b178`): `BuatKartuKanbanTool` — nama penerima tak cocok user → kartu TETAP dibuat tanpa assignee (kolom sering dinamai orang tanpa akun); cocok sebagian ("Tiar"→"Bahtiar Tiar"). Papan/kolom tetap wajib benar.
- **Peta SKU AJAX** (`a9e017b`): halaman Pesanan TikTok, simpan/hapus resep SKU tanpa reload (controller balas JSON saat wantsJson).

### C. Komunitas WA per role (`34968b7`, migrasi 000059)
Tombol hijau "Gabung Komunitas WA" di sidebar (per role user); ada QR → popup, tanpa QR → link langsung. Diatur di halaman **Pengumuman** (panel "Komunitas WA per Role"), izin `manage_announcements`. Model `CommunityLink`, view composer `$sidebarCommunity`.

### D. Pengumuman — Popup A+B (`8f3fe44`)
Popup dashboard muncul **sekali per hari** DAN **muncul lagi begitu diedit** (walau sesi/hari sama). Token = `md5(tanggal + sidik-jari popup [id:updated_at])` di session `ann_popups_token_{role}` (DashboardController).

### E. Laporan Income TikTok — Fase 1 (UPLOAD) (`1c2e513`, `20b79ef`, `e04a19e`)
Spec: **`TIKTOK_INCOME_SPEC.md`**. **Migrasi n8n "Tiktok income" → SKINKU.**
- `TikTokIncomeReportService` (`build()` bisa diuji dgn array, `fromFiles()`): gabung **"Semua pesanan" (.csv)** + **"income" (.xlsx sheet-1 "Detail pesanan")** by **Order ID** → qty per **item-besar** (`Product.category` via `TikTokOrderService::resolve()` yang dukung bundle "1 SKU = N pcs") → deteksi SKU tak dikenal. **Report-only (TAK potong stok** — stok tetap jalur API biar tak dobel).
- Indeks kolom CSV: `[0]`Order ID, `[5]`SKU ID, `[6]`Seller SKU, `[9]`Qty. Income xlsx: `[0]`ID Pesanan, `[3]`waktu, `[5]`settlement, `[6]`revenue, `[14]`fee. `SpreadsheetReader` auto-trim `\t` nyangkut.
- **Auto-isi Seller SKU kosong** (`e04a19e`): order lama sering Seller SKU blank (kode diisi belakangan). Diisi dari SKU ID yang sama **HANYA kalau tak ambigu** (1 SKU ID = 1 kode). Ambigu (mis. `BBC-1`/`DC-1` share 1 SKU ID) → tak ditebak.
- `TikTokIncomeController` (form/process/download/reset), hasil di **session** (stateless), unduh **Excel** (`XlsxWriter`, Order ID tetap teks, kolom item dinamis). Sub-halaman Integrasi TikTok "🧾 Laporan Income", route `tiktok.income.*`, izin `manage_tiktok`.

### F. OKR berbasis AI
Spec: **`OKR_SPEC.md`**. Migrasi `000063` sampai `000066`.

- Alur minim setting: pilih bulanan/kuartalan + cakupan perusahaan/tim/individu + arahan; papan utama opsional.
- AI membaca Pengetahuan AI + anggota/papan/kolom aktif, lalu membuat draf `Objective → Key Result → tugas per individu`.
- **Panel spesialis:** CMO AI + CFO AI + COO AI membuat usulan bidang masing-masing secara paralel, lalu AI Orchestrator menyelaraskan hasil final. Total tetap 4 giliran provider, tetapi waktu tunggu panel tidak dijumlahkan.
- **Data aktual per fungsi:** CMO baca penjualan/channel/KOL/TikTok; CFO baca laporan keuangan/margin/piutang/settlement; COO baca stok/produksi/PO/operasional. Semua disaring menurut izin user lewat `OkrBusinessSnapshotService`.
- Snapshot analitis diperluas dengan tren tiga bulan, pemisahan omzet e-commerce
  dan distributor, funnel distributor Rp100 juta, portofolio produk, tren
  keuangan/produksi, serta keterbatasan data affiliate yang eksplisit.
- Model hanya boleh mengutip `source_path` dari katalog server. Nilai bukti
  diambil ulang dari hasil query oleh server, sehingga angka buatan model tidak
  dapat masuk ke pratinjau.
- Bila format `source_path` dari panel spesialis meleset, server melengkapi
  minimal dua bukti langsung dari katalog bidang tersebut. Variasi format model
  tidak lagi menggagalkan seluruh proses; quality gate final tetap ketat.
- Jika ringkasan Orchestrator generik, bukti palsu/kurang, baseline kabur, atau
  konflik kosong, server memulihkannya dari hasil panel dan katalog data yang
  sudah terverifikasi. Draf baru ditolak bila tiga panel memang tidak mempunyai
  data/diagnosis atau struktur Objective/KR/tugas yang cukup.
- Pratinjau menampilkan Dasar Analisis AI, bukti terverifikasi, asumsi/data gap,
  konflik dan keputusan BOD, cakupan sumber yang dibaca, rationale Objective,
  serta baseline dan gap setiap Key Result.
- CMO/CFO/COO hanya perspektif AI; BOD dan PIC manusia tetap diinferensikan dari Pengetahuan AI (tidak ada setting mapping jabatan baru).
- Setiap Objective menyimpan label spesialis `cmo/cfo/coo`; label ikut terlihat di pratinjau, halaman aktif, dan deskripsi kartu.
- Sidebar dirapikan: link **Pengetahuan AI** yang sebelumnya tampil dua kali sekarang hanya satu; menu **OKR** tampil setelah Kanban.
- Pratinjau utama ringkas dan siap-setujui: AI otomatis mengisi detail pekerjaan,
  owner BOD, PIC, tenggat, dan kolom To Do bernama PIC. Edit dilakukan langsung
  pada kartu Objective/KR/tugas yang dipilih; tidak ada form kedua. Konten halaman
  dibatasi `max-w-6xl` agar tidak melebar.
- Normalisasi defensif membaca pola BOD (`Freddie — CMO`, dst.) dan aturan
  delegasi (`jenis pekerjaan → Nama`) langsung dari bagian Tim & tanggung jawab.
- Owner BOD kini punya `owner_name` terpisah dari akun portal. Migrasi `000064`
  membackfill draf lama dari Pengetahuan AI; BOD tidak perlu dibuatkan akun
  hanya agar namanya tampil sebagai penanggung jawab.
- Freddie/Billy/Devrina sementara memakai akun teknis Super Admin. Migrasi
  `000065` menyimpan `assignee_name`, sehingga nama PIC dan kolom To Do tetap
  mengikuti BOD. Tiap Objective memperoleh satu kartu review/approval BOD.
- Coverage CMO memastikan video/UGC ke Gracelyn, KOL/affiliate ke Tiar, dan
  desain/promosi ke Agatha bila workstream disebut. Hida tanpa akun/kolom diberi
  peringatan dan tidak dialihkan diam-diam.
- PIC dan kolom bernama anggota diselaraskan lagi saat koreksi disimpan, sehingga
  PIC Agatha tidak dapat tertinggal di `To Do Tiar`. Detail dan owner wajib
  sebelum approval; draf versi lama yang kosong diberi peringatan khusus.
- Persetujuan eksplisit membuat semua kartu Kanban secara atomik; kartu memakai `created_via=ai` dan tertaut ke tugas OKR.
- Progres Objective/KR otomatis dari `BoardCard.completed_at`; masuk/keluar Done/Selesai langsung mengubah progres.
- Bagian Pengetahuan AI baru: **Strategi & aturan OKR**.
- Izin baru: `okr.view` (tim internal) dan `okr.manage` (default hanya super admin).
- Uji offline: `tests/Feature/OkrTest.php`; total suite setelah quality gate
  analisis **512 lulus / 2300 assertions**.

---

## 3. YANG PERLU DILAKUKAN USER (setelah deploy)

1. **OpenAI:** set **spending cap** (Billing → Usage limits). Key sudah di `.env`.
2. **Laporan Income TikTok:** upload file asli → cek (a) angka uang bener (kalau meleset, indeks kolom income `[5]/[6]/[14]` mungkin geser), (b) kolom item-besar rapi (dari `Product.category`), (c) "SKU tak dikenal" → lengkapi di **Peta SKU** (halaman Pesanan TikTok, sekarang tanpa reload).
3. **Komunitas / Pengumuman:** cek fitur baru sesuai kebutuhan.
4. **OKR:** isi bagian "Strategi & aturan OKR", generate satu draf kecil, periksa label CMO/CFO/COO + pembagian orang/kolom/tenggat, baru approve. Satu generate = 3 panel paralel + 1 Orchestrator.

---

## 4. NEXT / OPEN ITEMS (buat dilanjut)

- **Laporan Income TikTok Fase 2 (OTOMATIS dari API, tanpa upload):** butuh sync **settlement per-order** (`TiktokSettlement` sekarang tersimpan per-BATCH/statement, `order_ids` array — belum per-order). Tarik `statement_transactions`. Bisa hybrid (upload income buat sisi uang).
- **Asisten AI lanjutan:** streaming jawaban · riwayat chat tersimpan (tabel) · provider **Anthropic** (interface siap, tinggal 1 kelas `AnthropicProvider` + daftar di factory) · alat lain (KOL/penjualan/stok/edit-hapus) · akses role selain super_admin (tinggal beri izin `use_ai_assistant`).
- **Notifikasi pengajuan dealing KOL** (badge deal draft) — pernah disinggung, BELUM dibangun; alur (draft vs approve, in-app vs email) belum diputuskan.
- **OKR lanjutan (opsional):** tambah/hapus baris Objective/KR/tugas secara manual di pratinjau; metrik aktual berbasis data bisnis (sekarang progres sengaja berbasis kartu selesai).

---

## 5. FILE PENTING (peta cepat)

- Spec: `AI_ASSISTANT_SPEC.md`, `TIKTOK_INCOME_SPEC.md`, `OKR_SPEC.md`, `ACCOUNTING_SPEC.md`.
- AI: `app/Services/Ai/**`, `app/Http/Controllers/AiAssistantController.php`, `resources/views/ai/*`, `resources/views/partials/ai-widget.blade.php`, `app/Models/AiKnowledge.php`.
- TikTok Income: `app/Services/TikTokIncomeReportService.php`, `app/Http/Controllers/TikTokIncomeController.php`, `resources/views/tiktok/income.blade.php`.
- TikTok (existing, LENGKAP): `app/Services/TikTokOrderService.php` (`resolve/deduct/stockFunnel`), `TikTokController.php`, `TikTokSettlementService`, `TikTokAccountingService`.
- Kanban: `app/Services/KanbanKpiService.php`, `app/Http/Controllers/KanbanController.php`, `resources/views/kanban/*`, `app/Models/BoardCard.php`.
- OKR: `app/Services/{OkrAiService,OkrBusinessSnapshotService}.php`, `app/Http/Controllers/OkrController.php`, `app/Models/Okr*.php`, `resources/views/okr/*`.
- Helper zero-dep: `app/Support/{SpreadsheetReader,XlsxWriter,Text,Permissions}.php`.
- Izin: `app/Support/Permissions.php` (`use_ai_assistant`, `okr.view`, `okr.manage`, `manage_tiktok`, `manage_announcements`, `kanban.view`, dll).
