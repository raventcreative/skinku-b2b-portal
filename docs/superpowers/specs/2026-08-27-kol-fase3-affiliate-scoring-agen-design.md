# Spec — Sub-menu KOL Fase 3: Affiliate & GMV · Skor (APS/KSS) · Agen scraper

Tanggal: 2026-08-27 · Status: **DISETUJUI user** (build semua a/b/c) · Repo: skinku-b2b-php

Lanjutan Fase 1 (pipeline/reminder/konten) + Fase 2 (deal & budget). Rumus di-port dari
app lokal `Iyuro/skinku` (`src/lib/metrics.ts` + `src/lib/scoring.ts`). Migrasi mulai `000101`.

## 3a — Affiliate & GMV (fondasi data)

**Migrasi 000101 — `kol_affiliate_transactions`:**
- `id`; `platform` string (tiktok|shopee); `order_id` string; `kol_id` FK→kols nullable nullOnDelete
  (null = belum cocok); `raw_username` string (buat pencocokan); `gmv` unsignedBigInteger;
  `commission` unsignedBigInteger nullable; `qty` unsignedInteger nullable; `product` string nullable;
  `status` string nullable; `content_type` string nullable; `order_date` date;
  `source` string (import|agent|manual) default import; `created_by` FK→users nullOnDelete; timestamps.
- **unique(`platform`,`order_id`)** → dedup; re-import periode sama = replace baris (updateOrCreate).

**Model `KolAffiliateTransaction`** — fillable semua di atas; cast order_date:date; relasi `kol()`.
Scope `matched()`/`unmatched()` (kol_id null). Konstanta status batal (utk kecualikan GMV).

**Service `KolAffiliateService`:**
- `import(array $rows, string $platform, int $actorId): array{imported, matched, unmatched}` — dedup
  by (platform, order_id); cocokkan `raw_username` ke `kols.tiktok_username` (case/@-insensitive) →
  isi kol_id, sisanya null.
- `monthly(Carbon $month): Collection` — ranking per creator: gmv, orders, commission (kecuali status batal).
- `weeklyGmv(int $kolId, Carbon $upTo, int $weeks=4): array<int>` — GMV per minggu (ISO week), lama→baru.
- `unmatched(): Collection` — grup raw_username belum cocok, urut GMV desc (ringkasan nilai).
- `matchUsername(string $rawUsername, int $kolId)` — tautkan semua transaksi username itu ke kol.

**Import file** — upload XLSX/CSV (reuse `SpreadsheetReader` zero-dep). Kolom di-map: username, order_id,
gmv, commission, qty, product, status, order_date. Mapping tersimpan (AppSetting) per platform.
Halaman `kol-affiliate.import` (2 tahap: parse→preview→commit), gated `kol.affiliate.manage`.

**Halaman Affiliate & GMV** (`kol-affiliate.index`, gated `kol.view`; angka uang butuh
`kol.affiliate.view`): nav bulan; ringkasan (GMV bulan, komisi, order, affiliate aktif); GMV per minggu;
tabel ranking per creator (GMV, order, komisi, APS bila ada). Link ke "Belum Cocok".

**Izin baru:** `kol.affiliate.view` (lihat GMV/komisi — sensitif), `kol.affiliate.manage` (import/cocok).
Default `['kol_specialist']` untuk keduanya; super_admin implisit.

## 3b — Skor (APS + KSS)

**Helper `App\Support\KolMetrics`** (fungsi murni port dari metrics.ts): `cpm(cost,views)`,
`ecpm(rate,median)`, `rpm(gmv,views)`, `roas(gmv,cost)`, `pace(mtd,day,daysInMonth,target)`,
`growthVelocity(array $weekly)`, `consistency(array $weekly)` → {avgPerWeek, activeWeeks, hasTwoWeekGap},
`median(array)`. Semua null-safe (views/cost 0 → null).

**Service `App\Services\KolScoringService`:**
- `aps(array $input): array` — port `scoreAps`: growth 35% + RPM 25% + konsistensi 20% + skala 20%;
  RPM null → bobot dialihkan (reweight); cap 40 bila 2 minggu terakhir 0 konten; <4 minggu data →
  status "new". Label ≥75 bina_intensif / ≥50 pantau / <50 nurture. Threshold PERSIS Iyuro.
- `kss(array $input): array` — port `scoreKss`: eCPM 35% + ER 20% + niche 20% + riwayat 15% +
  kesiapan 10%; barter-only → eCPM 90. Keputusan ≥70 shortlist / ≥50 nego / <50 tolak + advice.

**APS ranking** — di halaman Affiliate & GMV (3a): per creator, input APS dirakit dari
`weeklyGmv` (3a) + jumlah konten mingguan (`kol_contents` Fase 1) + views bulan (snapshot Fase 1).
Kolom skor + label berwarna + breakdown (accordion).

**KSS kalkulator** (`kol-skor.kss`, gated `kol.view`) — form: pilih KOL (pre-fill median views dari
screening terbaru + followers), rate, barter-only, ER, niche (4 pilihan), riwayat (4), kesiapan (3)
→ tampilkan skor + keputusan + advice + breakdown. Tidak menyimpan (kalkulator murni; opsional catat
ke screening nanti). Median auto-isi dari `KolScreening::median_views` bila ada.

**Non-goal 3b:** tidak menyimpan hasil APS ke tabel (dihitung on-the-fly per request; sama seperti
level/verdict yang accessor). KSS tidak menulis DB.

## 3c — Agen scraper (portal side + kontrak)

**Endpoint** `POST /api/kol-agent/affiliate` (di luar grup auth web; verifikasi header
`X-Agent-Token` == config `services.kol_agent.token` dari .env `KOL_AGENT_TOKEN`; dikecualikan CSRF
di bootstrap/app.php). Body: `{platform, transactions:[{order_id, username, gmv, commission, qty,
product, status, order_date}]}` → `KolAffiliateService::import(..., source:'agent')` → balas
`{imported, matched, unmatched}`. Rate-limit sederhana + tolak bila token kosong.
Endpoint `POST /api/kol-agent/snapshots` (opsional) → snapshot views konten (source agent).

**Sisi app Iyuro (repo terpisah, di luar scope kode portal ini):** app lokal setelah sync/scrape
memanggil endpoint di atas dengan token. Didokumentasikan; perubahan kode app Iyuro = follow-up.

**Non-goal 3c:** tidak mengubah repo Iyuro dari sini (cuma portal endpoint + dok kontrak).

## Testing
Pola suite existing (`/c/php83/php.exe artisan test`). Per sub-fase: import dedup+match+unmatched;
monthly ranking; weeklyGmv; APS math (semua cabang: new/cap/reweight/label); KSS math (barter, tiap
keputusan); endpoint agen (token salah 401, token benar simpan source=agent); izin 403 per role.
Pint --dirty + suite penuh hijau sebelum tiap commit.

## Urutan bangun
3a (migrasi+model+service+import+halaman+belum-cocok) → 3b (metrics+scoring+APS ranking+KSS kalkulator)
→ 3c (endpoint agen+dok). Commit per potong; aman berhenti & lanjut kapan saja.
