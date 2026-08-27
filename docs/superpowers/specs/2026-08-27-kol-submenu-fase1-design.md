# Spec — Sub-menu KOL Fase 1: Pipeline, Reminder, Konten & Views

Tanggal: 2026-08-27 · Status: **DISETUJUI user** (chat) · Repo: skinku-b2b-php (system.skinku.id)

## 1. Konteks & tujuan

Fitur app lokal "SKINKU KOL & Affiliate Command Center" (repo `Iyuro/skinku`, Next.js+SQLite,
copy lokal ter-fix di `C:\Users\DELL\Downloads\skinku-kol`) diintegrasikan ke portal Laravel
sebagai **sub-menu baru di grup KOL** — **BUKAN mengganti** menu/fitur KOL yang sudah ada.

**Keputusan arsitektur (dikunci user):** fitur dibangun **native di Laravel** (multi-user, satu
database MySQL, satu login). Scraper TIDAK ikut pindah — prod = shared hosting tanpa
Chrome/Playwright; scraper kelak jadi **agen lokal** di PC user yang menyetor data via API
token (fase berikutnya, bukan fase ini).

**Skop Fase 1 (dikunci user):** Pipeline scouting + Reminder, dan Konten & Views.
Deal & Budget serta Affiliate & GMV + skor APS/KSS = fase berikutnya.

## 2. Navigasi

Sidebar: item tunggal "KOL" diganti **grup accordion "KOL"** (pola `toggleNavGroup` sama
dengan grup Integrasi), anak-anaknya (tampil sesuai izin masing-masing):

| Sub-menu | Route name | Syarat tampil |
|---|---|---|
| Database KOL (existing) | `kols.index` | `kol.view` |
| Deal KOL (existing, diangkat ke menu) | `kol-deals.index` | `kol.deal.manage` |
| Pipeline 🆕 | `kol-pipeline.index` | `kol.view` |
| Konten & Views 🆕 | `kol-konten.index` | `kol.view` |
| Reminder 🆕 | `kol-reminder.index` | `kol.view` |

Grup tampil bila minimal satu anak tampil. Ikon grup pakai path `kols.index` (bintang).
Active-state: `kol*` menyalakan grup; per-anak pakai pola route masing-masing
(`kols.*`+`kol-screenings.*`+`kols.import*` untuk Database; `kol-deals.*`; `kol-pipeline.*`;
`kol-konten.*`; `kol-reminder.*`).

## 3. Data (migrasi `2026_01_01_000099` + `000100`)

Semua entitas menempel ke tabel **`kols` yang sudah ada** — tidak ada tabel creator baru.

### 000099 — `kol_pipeline_cards` + `kol_pipeline_events`

`kol_pipeline_cards` (satu kartu aktif per KOL per jalur):
- `id`; `kol_id` FK→kols cascadeOnDelete; `track` string default `'kol'`
  (disiapkan untuk `'affiliate'` di fase depan); **unique(`kol_id`,`track`)**
- `stage` string — nilai sah: `kandidat, dihubungi, nego, deal, sampel_dikirim, posting,
  evaluasi, repeat, drop` (urutan kolom kanban persis ini)
- `next_action` string nullable; `next_action_at` date nullable
- `followup_count` unsignedTinyInteger default 0
- `note` text nullable; `created_by` FK→users nullOnDelete; timestamps
- index(`stage`), index(`next_action_at`)

`kol_pipeline_events` (append-only — riwayat tidak pernah di-update/hapus):
- `id`; `card_id` FK→kol_pipeline_cards cascadeOnDelete
- `from_stage` string nullable (null = kartu dibuat); `to_stage` string
- `note` string nullable; `created_by` FK→users nullOnDelete; `created_at` saja

### 000100 — `kol_contents` + `kol_content_snapshots`

`kol_contents`:
- `id`; `kol_id` FK→kols cascadeOnDelete; `kol_deal_id` FK→kol_deals nullable nullOnDelete
- `platform` string default `'tiktok'` (nilai dari `config('kol.platforms')`)
- `url` string; `title` string nullable
- `label` string — `'paid'` | `'earned'`. **Aturan anti-dobel-hitung:** konten ber-`kol_deal_id`
  otomatis `paid`; tanpa deal default `earned` (boleh dioverride manual)
- `posted_at` date; `created_by` FK→users nullOnDelete; timestamps
- index(`kol_id`), index(`posted_at`)

`kol_content_snapshots` (append-only per tanggal; hari sama = replace):
- `id`; `kol_content_id` FK→kol_contents cascadeOnDelete
- `views` unsignedBigInteger; `likes`/`comments`/`shares` unsignedBigInteger nullable
- `captured_on` date; `source` string `'manual'`|`'agent'` (kolom agen disiapkan sekarang)
- `created_by` FK→users nullOnDelete; `created_at`
- **unique(`kol_content_id`,`captured_on`)** → upsert per hari (snapshot harian terakhir menang)

Setting baru (AppSetting, tanpa migrasi): `kol_views_target` default `1000000` —
target views bulanan, diedit inline di halaman Konten (butuh `kol.content.manage`).

## 4. Izin (Permissions.php)

| Key | Label | DEFAULTS |
|---|---|---|
| `kol.pipeline.manage` | Kelola Pipeline KOL (kartu scouting) | `['kol_specialist']` |
| `kol.content.manage` | Kelola Konten & Views KOL | `['kol_specialist']` |

super_admin selalu implisit. Halaman BACA di balik `kol.view` (grup route existing);
semua aksi TULIS di balik izin manage masing-masing (nested middleware, pola sama dengan
`kol.screening.manage`).

## 5. Halaman & perilaku

### 5a. Pipeline — `GET /kol-pipeline` (`kol-pipeline.index`)

- Papan kanban 9 kolom sesuai urutan stage §3. Kartu: nama KOL (link `kols.show`), badge
  follower/level, `next_action` + tanggal (merah bila `< today`, kuning bila hari ini/besok),
  chip `FU n×`, tanda ⚠ bila kartu aktif tanpa next action.
- Header stat: **Kartu aktif** (stage ≠ drop) · **Terlambat** · **Hari ini/besok** ·
  **Tanpa next action**.
- Aksi (butuh `kol.pipeline.manage`):
  - `POST /kol-pipeline` — buat kartu: pilih KOL existing (select native, label nama-depan
    sesuai preferensi user) + stage awal (default `kandidat`) + next action + tanggal.
    Duplikat (kol_id,track) → error validasi "KOL ini sudah punya kartu".
  - `PATCH /kol-pipeline/{card}/stage` — pindah stage (dropdown "Pindah ke…" per kartu,
    tanpa drag-drop di fase 1). Menulis 1 baris `kol_pipeline_events`.
  - `PATCH /kol-pipeline/{card}/next-action` — ubah next action + tanggal + increment
    `followup_count` (checkbox "ini follow-up").
  - `DELETE /kol-pipeline/{card}` — hapus kartu (super_admin saja; jalur normal = stage `drop`).
- Aturan bisnis dari PANDUAN app asal: follow-up maks 3× lalu parkir ke `drop` —
  ditampilkan sebagai hint, tidak dipaksa sistem.

### 5b. Reminder — `GET /kol-reminder` (`kol-reminder.index`)

Baca-saja, agregat dari pipeline (fase 1), urut prioritas:
1. **Terlambat** — kartu aktif `next_action_at < today` (paling lama dulu)
2. **Jatuh tempo hari ini**
3. **Tanpa next action** — kartu aktif dengan `next_action_at` null

Tiap baris: KOL, stage, next action, tanggal, tombol "Buka" → anchor kartunya di Pipeline.
Catatan di halaman: sumber reminder fase depan (pembayaran deal, deadline posting, affiliate
berhenti posting) menyusul.

### 5c. Konten & Views — `GET /kol-konten?bulan=YYYY-MM` (`kol-konten.index`)

- Navigasi bulan (default bulan berjalan; filter `posted_at` dalam bulan).
- Ringkasan: **Total views** (snapshot terakhir per konten, dijumlah) · **Paid vs Earned**
  (nilai + %) · **Target & pace** — proyeksi akhir bulan = `total × (hari_dalam_bulan /
  hari_berjalan)`, banding `kol_views_target`; badge Aman ≥95% proyeksi, Berisiko <95%.
- Tabel konten: KOL, judul (link URL, target _blank), label chip paid/earned, deal (kode,
  bila ada), posted_at, views terakhir + tanggal snapshotnya.
- **Grid isi massal** (butuh `kol.content.manage`): `GET /kol-konten/grid` — semua konten
  bulan berjalan sebagai baris input angka (views wajib, likes/comments/shares opsional);
  `POST /kol-konten/grid` menyimpan snapshot `captured_on = today` per baris yang diisi
  (baris kosong dilewati; hari sama = replace). Sumber `manual`.
- Tambah konten (butuh `kol.content.manage`): `GET /kol-konten/create` + `POST /kol-konten` —
  field: KOL (select), URL (wajib), platform, judul (opsional), label, deal (opsional,
  daftar deal KOL tsb), posted_at (default today).
  **Autofill judul:** `POST /kol-konten/oembed` (AJAX, butuh manage) — server fetch
  `https://www.tiktok.com/oembed?url=...` (allowlist host tiktok.com saja, timeout 10 dtk,
  gagal = diam, judul tetap manual).
- Edit/hapus konten: `GET /kol-konten/{content}/edit`, `PUT /kol-konten/{content}`,
  `DELETE /kol-konten/{content}` (manage; hapus ikut menghapus snapshot — cascade).

## 6. Agen scraper (fase depan — HANYA kontrak, TIDAK dibangun sekarang)

Sketsa: agen lokal (app Next.js dirampingkan) `POST /api/kol-agent/snapshots` dengan header
`X-Agent-Token` (nilai di .env `KOL_AGENT_TOKEN`); body: daftar `{url, views, likes, ...,
captured_on}` → dicocokkan ke `kol_contents.url`, tulis snapshot `source='agent'`.
Fase 1 hanya memastikan skema siap (kolom `source`); endpoint TIDAK dibuat.

## 7. Testing (pola suite existing, runner /c/php83/php.exe artisan test)

- `KolPipelineTest`: tanpa `kol.view` semua route 403; buat kartu (+ event lahir); duplikat
  kartu ditolak; pindah stage menulis event; next-action + followup_count; user `kol.view`
  tanpa manage tidak bisa POST (403); reminder mengurutkan terlambat→hari ini→tanpa aksi.
- `KolContentTest`: render index kosong; tambah konten (deal→paid otomatis); grid massal
  membuat snapshot; submit ulang hari sama = replace (tetap 1 baris); ringkasan
  paid/earned & pace benar; oEmbed di-mock (`Http::fake`); izin manage dihormati.
- Sidebar: grup KOL tampil utk kol_specialist, tidak utk reseller.
- Pint --dirty + suite penuh hijau sebelum tiap commit.

## 8. Non-goals Fase 1 (sengaja TIDAK dibangun)

Drag-drop kanban · endpoint agen & scraper porting · Deal & Budget baru (pakai Deal KOL
existing) · Affiliate & GMV, APS/KSS · pipeline track affiliate (kolom disiapkan saja) ·
multi-akun platform per KOL · dashboard/analytics tambahan.

## 9. Fase berikutnya (urutan indikatif)

Fase 2: Deal & Budget (perluas kol_deals: budget bulanan, reminder pembayaran, CPM anchor).
Fase 3: Affiliate & GMV + APS/KSS + agen scraper + halaman Sync status.
