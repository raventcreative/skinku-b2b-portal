# Multi-backup AI (Rantai Failover Berlapis) — Desain

**Tanggal:** 2026-09-15
**Repo:** `skinku-b2b-php`
**Status:** Disetujui (design) — siap dibuat rencana implementasi.

## Tujuan

Perpanjang rantai failover AI dari **satu** cadangan menjadi **beberapa** cadangan
berurutan: `OpenAI (primary) → cadangan-1 → cadangan-2 → cadangan-3`. Kalau primary
gagal, request yang sama dicoba ke cadangan-1; kalau cadangan-1 juga gagal, lanjut ke
cadangan-2; dst. Kasus nyata: model GPT di 9router (OpenRouter) mati → jatuh ke model
Gemini di 9router.

## Ruang Lingkup

**Termasuk:**
- Sampai **3 slot cadangan** (`AI_BACKUP_*`, `AI_BACKUP2_*`, `AI_BACKUP3_*`) → rantai
  maksimal 4 dalam (1 primary + 3 cadangan).
- Perakitan rantai di `AiProviderFactory`; config slot bernomor di `config/services.php`.
- **Aturan warisan:** slot ke-2/3 yang `KEY`/`BASE`-nya dikosongkan mewarisi milik
  cadangan-1 (base default cadangan-1 = OpenRouter). Jadi "satu akun 9router, model beda"
  cukup mengisi `AI_BACKUP2_MODEL`.

**DI LUAR scope (YAGNI):**
- UI pengaturan provider (tetap lewat `.env`).
- Health-check / retry per provider (sudah ada di dalam tiap `OpenAiProvider`).
- Lebih dari 3 cadangan.
- Mengubah semantik failover (`FailoverAiProvider` tak disentuh).

## Fakta Kode (hasil telaah)

- `FailoverAiProvider(array $providers)` **sudah** mencoba provider **berurutan** sampai
  satu berhasil; failover untuk **semua** `AiException` (kuota/billing, key ditolak,
  rate-limit, down); melempar error terakhir bila semua tumbang. Mendukung N provider —
  **tak perlu diubah**.
- `AiProviderFactory::make()` sekarang: bikin `$primary`, panggil `backup()` (baca 1 slot
  `config('services.ai.backup.*')`, null bila `key`/`model` kosong), lalu bila ada →
  `new FailoverAiProvider([$primary, $backup])`, kalau tidak → `$primary` saja.
- `backup()` konstruksi `OpenAiProvider($key, $base, $model, $maxTokens, $timeout, $connectTimeout, sequential:)`.
- `OpenAiProvider::__construct(string $apiKey, string $base, string $model, int $maxTokens, int $timeout=45, int $connectTimeout=10, bool $sequential=false)`.
- `config/services.php` `ai.backup` = `key` (AI_BACKUP_KEY), `base` (AI_BACKUP_BASE, default
  `https://openrouter.ai/api/v1`), `model` (AI_BACKUP_MODEL), `timeout` (AI_BACKUP_TIMEOUT,
  default 60), `sequential` (AI_BACKUP_SEQUENTIAL, default false).
- Konstruksi `OpenAiProvider` **tidak** memanggil jaringan (jaringan hanya saat `->chat()`),
  jadi `make()` & perakitan rantai bisa dites tanpa mock HTTP.

## Arsitektur & Alur Data

Runtime **tak berubah** — hanya perakitan objek saat `make()`:

```
AiProviderFactory::make()
  → $primary = OpenAiProvider (dari config openai/AppSetting)
  → $backups = backupChain($maxTokens)   // array<OpenAiProvider>, urut, hanya slot aktif
       ← resolvedBackupSlots()           // MURNI: baca 3 slot config, terapkan warisan, filter aktif
  → $backups kosong → kembalikan $primary  (persis perilaku sekarang saat tak ada cadangan)
  → ada isi → new FailoverAiProvider([$primary, ...$backups])
```

Semua pemanggil `AiProviderFactory::make()` (Asisten AI, OKR, **Chat E-commerce**) otomatis
dapat rantai lebih panjang — tak ada perubahan di sisi pemanggil.

## Komponen

### 1. Config (`config/services.php`)
Di dalam array `ai`, tambah dua slot mirror `backup` (jangan ubah `backup` yang ada —
backward-compatible). Bentuk tiap slot: `key`, `base`, `model`, `timeout`, `sequential`.

- `ai.backup` (slot 1) — **tak berubah**: `AI_BACKUP_KEY` / `AI_BACKUP_BASE` (default OpenRouter) / `AI_BACKUP_MODEL` / `AI_BACKUP_TIMEOUT` (60) / `AI_BACKUP_SEQUENTIAL` (false).
- `ai.backup2` — `AI_BACKUP2_KEY` / `AI_BACKUP2_BASE` (**tanpa default** — kosong = warisi slot-1) / `AI_BACKUP2_MODEL` / `AI_BACKUP2_TIMEOUT` (60) / `AI_BACKUP2_SEQUENTIAL` (false).
- `ai.backup3` — `AI_BACKUP3_*` (pola sama seperti `backup2`).

`.env.example` diberi baris komentar untuk `AI_BACKUP2_*` / `AI_BACKUP3_*` (dokumentasi).

### 2. Factory (`AiProviderFactory`)
- **Ganti** metode privat `backup(int $maxTokens): ?OpenAiProvider` dengan dua metode:
  - `resolvedBackupSlots(): array` — **murni, fungsi dari config**. Baca `ai.backup`,
    `ai.backup2`, `ai.backup3` berurutan. Untuk slot ke-2/3: bila `key` kosong → pakai
    `ai.backup.key`; bila `base` kosong → pakai `ai.backup.base`. Sertakan slot **hanya
    bila** `key` (hasil warisan) **dan** `model` keduanya non-kosong. Kembalikan array urut
    berisi slot ter-resolve: `['key','base','model','timeout','sequential']`.
  - `backupChain(int $maxTokens): array` — map `resolvedBackupSlots()` → `OpenAiProvider[]`
    (pakai `connect_timeout` global seperti `backup()` sekarang).
- **`make()`**: `$backups = self::backupChain($maxTokens); if ($backups === []) return $primary; return new FailoverAiProvider([$primary, ...$backups]);`

**Batasan interface (tetap sama):** `AiProviderFactory::make(): AiProvider`.
`resolvedBackupSlots()` dibuat **`public static`** supaya logika inti (slot bernomor +
warisan + filter aktif) bisa diuji langsung tanpa refleksi; `backupChain()` boleh privat.

### 3. `FailoverAiProvider` — TAK DIUBAH
Sudah menerima & mencoba N provider berurutan. Menambah anggota array = memperpanjang rantai.

## Penanganan Error / Kasus Batas

- Semua slot cadangan kosong → `make()` kembalikan **primary saja** (tak dibungkus Failover),
  identik perilaku sekarang.
- Slot punya `model` tapi `key` (setelah warisan) tetap kosong → slot **di-skip** (tak
  memecah rantai).
- Urutan **dijaga**: cadangan-1 sebelum cadangan-2 sebelum cadangan-3.
- Warisan hanya untuk `key` & `base` (identitas akun yang merepotkan diulang); `timeout` &
  `sequential` pakai default per-slot sendiri (60 / false).
- Semua provider gagal → `FailoverAiProvider` melempar `AiException` terakhir (tak berubah).
- `sequential` per-slot dihormati (dipakai `chatMany` panel OKR paralel).

## Rencana Tes

Semua tanpa jaringan (konstruksi provider tak memanggil HTTP; `resolvedBackupSlots()` murni).

- **`resolvedBackupSlots()`** (inti logika):
  - hanya `backup` (slot-1) diisi → 1 slot.
  - `backup` + `backup2` diisi → 2 slot, **urut** (slot-1 dulu).
  - `backup2` model diisi tapi `AI_BACKUP2_KEY` kosong → **warisi** `AI_BACKUP_KEY`
    (assert `key` slot-2 == key slot-1).
  - `backup2` `base` kosong → warisi `AI_BACKUP_BASE` (assert base slot-2 == base slot-1).
  - `backup2` model diisi tapi slot-1 & slot-2 key dua-duanya kosong → slot-2 **di-skip**.
  - tak ada cadangan sama sekali → array kosong.
  - ketiga slot diisi → 3 slot urut.
- **`FailoverAiProvider` 3-level** (pakai fake `ConcurrentAiProvider` seperti `AiFailoverTest`):
  primary lempar → cadangan-1 lempar → cadangan-2 sukses → hasil dari cadangan-2; semua lempar
  → error terakhir dilempar.
- **`make()` integrasi** (config di-set via `config()->set(...)`):
  - `backup` + `backup2` di-set → `make()` kembalikan `FailoverAiProvider` (assert instanceof).
  - tanpa cadangan → `make()` kembalikan `OpenAiProvider` (assert **bukan** `FailoverAiProvider`).

## File yang Disentuh

- `config/services.php` — tambah `ai.backup2`, `ai.backup3`.
- `.env.example` — baris komentar `AI_BACKUP2_*` / `AI_BACKUP3_*`.
- `app/Services/Ai/AiProviderFactory.php` — ganti `backup()` → `resolvedBackupSlots()` + `backupChain()`; ubah `make()`.
- `tests/Feature/` (atau `tests/Unit/`) — tes `resolvedBackupSlots()`, `make()` chain, plus tambahan 3-level di tes failover yang ada.

## Deploy

Zero-dependency, hanya kode + config. Setup pengguna: isi `AI_BACKUP2_MODEL`
(dan `AI_BACKUP2_KEY` bila akun beda) di `.env` prod → `php artisan optimize:clear`.
Tanpa mengisi apa pun, perilaku identik dengan sekarang (satu cadangan / tanpa cadangan).
