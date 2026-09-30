# Dorong Foto ke Marketplace (Fase 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development.

**Goal:** Dorong foto Produk Master ke listing TikTok/Shopee (ganti-semua), digabung ke aksi "Dorong Konten" yang sudah ada, aman (skip-empty + diff-guard).

**Architecture:** 2 method upload multipart baru (helper JSON lama tak muat file) + `pushPhotos` di MarketplaceMasterService, lalu SET daftar foto pakai `partialEditProduct`/`updateItem` (Fase 1). `pushMasterContent` diperluas: per listing jalankan konten + foto.

**Tech:** Laravel 13/PHP 8.3, PHPUnit class-style, `Http::fake`+`Storage::fake('public')`+`UploadedFile::fake()->image()`. Tailwind compiled+committed (pakai kelas yang sudah ada / inline).

**Spec:** `docs/superpowers/specs/2026-09-30-dorong-foto-marketplace-design.md`

## Global Constraints

- **Zero-dependency** (no composer/npm).
- **HQ ISOLATION:** JANGAN sentuh products.hq_stock / stock_movements / InventoryService.
- **Content/foto push TERPISAH dari cron:** JANGAN ubah `pushListing`/`pushDirty`/`pushEach`/`MarketplacePushStockCommand`/`routes/console.php`.
- **4 pengaman foto:** (1) skip bila master tanpa foto (tak kosongkan listing); (2) diff-guard `photo_hash` (upload+ganti hanya bila set foto berubah / pertama kali); (3) confirm() sebut "ganti SEMUA foto"; (4) manual, bukan cron. Urutan foto master = urutan marketplace (pertama=cover).
- **Reuse:** `partialEditProduct`/`updateItem` (Fase 1) utk SET foto; conn/token helper; pola pushContent utk pushPhotos.
- **Blade:** no nested `<form>`; no `@json([...])` literal; hanya kelas Tailwind yang sudah ter-compile.
- **Commit:** TANPA trailer AI apa pun (CLAUDE.md:10 & AGENTS.md:72). Author = user.
- **Runner:** `C:/php83/php.exe artisan test`. Format: `C:/php83/php.exe vendor/bin/pint --dirty`.

---

### Task 1: Migrasi 000152 + kolom foto-sync di MarketplaceListing

**Files:** Create `database/migrations/2026_01_01_000152_add_photo_sync_to_marketplace_listings.php`; Modify `app/Models/MarketplaceListing.php` ($fillable); Test `tests/Feature/MarketplaceMaster/PhotoSyncMigrationTest.php`.

- [ ] **Step 1:** Migrasi `up()`: `Schema::table('marketplace_listings', fn (Blueprint $t) => …)` tambah `last_photo_status` (string 16 nullable, after 'content_hash'), `last_photo_error` (text nullable), `last_photo_pushed_at` (timestamp nullable), `photo_hash` (string nullable). `down()` drop keempat. (Lihat migrasi 000151 utk gaya + anchor `content_hash`.)
- [ ] **Step 2:** Tambah 4 kolom ke `$fillable` MarketplaceListing.
- [ ] **Step 3:** Test: `Schema::hasColumns` keempat true; buat listing → update keempat kolom → tersimpan.
- [ ] **Step 4:** `--filter=PhotoSyncMigration`; pint; commit (tanpa trailer).

---

### Task 2: Client uploadImage (TikTok + Shopee, multipart)

**Files:** Modify `app/Services/TikTokClient.php` + `app/Services/ShopeeClient.php`; Test `tests/Feature/MarketplaceMaster/PhotoUploadClientTest.php`.

**Interfaces (Produces):**
- `TikTokClient::uploadImage(string $accessToken, ?string $shopCipher, string $bytes, string $filename, string $useCase = 'MAIN_IMAGE'): string` — POST multipart `/product/202309/images/upload`, field `data`=file + `use_case`; sign dengan **body kosong** (`$bodyString=''`), query app_key/timestamp/(shop_cipher)/sign, header `x-tts-access-token`; return `data.uri` dari respons.
- `ShopeeClient::uploadImage(string $accessToken, string $shopId, string $bytes, string $filename): string` — POST multipart `/api/v2/media_space/upload_image`, field `image`=file; query auth (partner_id/timestamp/access_token/shop_id/sign); return `response.image_info.image_id`.

**Catatan implementasi:**
- Pelajari `TikTokClient::request()`/`sign()` — TIRU cara bangun query+sign, TAPI kirim `Http::attach('data', $bytes, $filename)->post($url.'?'.http_build_query($query))` dan `sign($path, $query, '')` (body string kosong). JANGAN pakai `request()` (ia paksa JSON).
- Pelajari `ShopeeClient::shopCall()`/`sign()` — sign Shopee tak sertakan body, jadi `Http::attach('image', $bytes, $filename)->post($url.'?'.http_build_query($auth))` dengan auth query yang sama.
- Baca `getWarehouses`/`getProduct` (TikTok) & `getItemBaseInfo` (Shopee) utk pola parse `$json['data']`/`$json['response']`.

- [ ] **Step 1 (test):** `Http::fake` `*images/upload*` → `{code:0,data:{uri:'tos://img1'}}`; panggil `uploadImage('tok','cip', 'BYTES','a.jpg')`; assert URL `/product/202309/images/upload`, method POST, request multipart membawa file (`$req->isMultipart()` / body berisi 'a.jpg'), header token, return `'tos://img1'`.
- [ ] **Step 2:** Implement TikTok `uploadImage`.
- [ ] **Step 3 (test):** `Http::fake` `*media_space/upload_image*` → `{error:'',response:{image_info:{image_id:'s0abc'}}}`; panggil `uploadImage('tok','SHOP1','BYTES','a.jpg')`; assert URL + multipart file + return `'s0abc'`.
- [ ] **Step 4:** Implement Shopee `uploadImage`.
- [ ] **Step 5:** `--filter=PhotoUploadClient`; pint; commit.

---

### Task 3: Mesin pushPhotos + integrasi ke pushMasterContent

**Files:** Modify `app/Services/MarketplaceMasterService.php`; Test `tests/Feature/MarketplaceMaster/PushPhotosTest.php`.

**Interfaces (Produces):** `photoHash(MarketplaceMaster $m): string`; `pushPhotos(MarketplaceListing $l, MarketplaceMaster $m, bool $force): string` (ok|failed|skip). **Modify** `pushMasterContent`.

**⚠️ GERBANG TikTok main_images:** sebelum/selagi implement, verifikasi `partial_edit` menerima `main_images` — cek bentuk `main_images` di respons `getProduct` asli bila memungkinkan; kalau ragu, tetap implement `['main_images'=>[['uri'=>…]]]` (bentuk standar 202309) dan ANDALKAN flash-jujur utk memunculkan penolakan. JANGAN diam bila gagal.

- [ ] **Step 1 (test):** `photoHash` — master 2 foto (Storage::fake + ImageService::attach) → hash stabil; ubah urutan (setFotoUtama) / tambah foto → hash berubah; master tanpa foto → `''`.
- [ ] **Step 2:** Implement `photoHash`: ambil `$m->filesIn(MarketplaceMaster::MASTER_IMAGE)->get(['id','sort_order'])`, map ke `[[id,sort_order],…]`, `md5(json_encode(...))`; `''` bila kosong.
- [ ] **Step 3 (test):** `pushPhotos` —
  - master tanpa foto → `'skip'`, `Http::assertNothingSent()`.
  - tiktok, 2 foto, koneksi ada, `Http::fake` (upload→uri, partial_edit→ok) → `'ok'`; `last_photo_status='ok'`, `photo_hash` terisi; jumlah request = 2 upload + 1 partial_edit.
  - ulang tanpa force (hash sama) → `'skip'` tanpa HTTP; force=true → kirim lagi.
  - upload gagal (fake error) → `'failed'`, `last_photo_error` terisi, `photo_hash` TAK berubah.
  - shopee → upload→image_id, `updateItem` set `image.image_id_list`.
- [ ] **Step 4:** Implement `pushPhotos` sesuai spec §6 (skip-empty → diff-guard → upload semua urut → set via partialEditProduct/updateItem → rekam). Untuk tiap file: `Storage::disk($f->disk ?: 'public')->get($f->path)` + `$f->original_name`. Reuse tiktokConn/token(+cipher), shopeeConn/token(+shop_id).
- [ ] **Step 5 (test):** `pushMasterContent` gabungan — konten ok + foto ok → listing dihitung 1 `pushed`; konten ok + foto gagal → `failed`; master tanpa foto → foto skip tapi konten tetap jalan; dua-dua skip → `skipped`. `pushListing`/`pushDirty` tak mengirim foto.
- [ ] **Step 6:** Modify `pushMasterContent`: per listing (`whereNotNull('item_id')`) hitung `$c=pushContent(...)`, `$p=pushPhotos(...)`, gabung: `failed` bila ada 'failed'; elif ada 'ok' → 'ok'; else 'skip'; tally. JANGAN sentuh pushListing/pushDirty/pushEach.
- [ ] **Step 7:** `--filter="PushPhotos|PushContent"`; pint; commit.

---

### Task 4: UI (confirm+label+status foto) + update tes terdampak + docs

**Files:** Modify `resources/views/marketplace-stock/form.blade.php` + `index.blade.php` (confirm text + label) + `channel.blade.php` (status foto); Modify `tests/Feature/MarketplaceMaster/PushContentActionTest.php` (KONFIRMASI + label) + `ContentStatusDisplayTest.php` bila perlu; Create `tests/Feature/MarketplaceMaster/PhotoStatusDisplayTest.php`; Modify `docs/SISTEM.md` + `docs/PETA-SISTEM.md`.

- [ ] **Step 1:** form.blade (kartu Dorong Konten, ~baris 149-153) + index.blade (menu Atur, ~baris 107): ubah teks confirm jadi menyebut **"dan ganti SEMUA foto"** + label tombol jadi **"Dorong konten & foto"**. Contoh confirm: `Kirim & timpa deskripsi/berat/dimensi dan ganti SEMUA foto listing di TikTok & Shopee? (field kosong dilewati)`. Pertahankan escaping `&amp;`.
- [ ] **Step 2:** channel.blade — tambah baris status **Foto** (`$lst?->last_photo_status` → "Foto: ok/gagal" merah bila gagal + `last_photo_error` via `{{ }}` + `Str::limit(...,80)` + title), mirror baris Konten yang sudah ada.
- [ ] **Step 3:** Update `PushContentActionTest`: konstanta `KONFIRMASI` + assertion label/confirm ke teks baru (deskripsi+foto). Bila ContentStatusDisplayTest meng-assert struktur channel yg bergeser, sesuaikan.
- [ ] **Step 4 (test baru):** `PhotoStatusDisplayTest` — listing `last_photo_status='failed', last_photo_error='Foto ditolak'` → channel `assertSee('Foto ditolak')` + penanda gagal; `ok` → penanda ok.
- [ ] **Step 5:** Docs: SISTEM.md (§ Stok Marketplace) + PETA-SISTEM.md — catat "Dorong Foto Fase 2 (digabung ke Dorong Konten; upload multipart images/upload + media_space; ganti-semua; skip-empty + diff-guard photo_hash; manual)".
- [ ] **Step 6:** `--filter="PushContentAction|ContentStatusDisplay|PhotoStatusDisplay|CatalogPage"`, lalu FULL suite `C:/php83/php.exe artisan test`; pint; commit.

---

## Self-Review
- Skip-empty (T3 pushPhotos) · diff-guard photo_hash (T3) · manual/no-cron (T3 tak sentuh pushDirty) · confirm ganti-semua (T4). ✓
- HQ isolation: tak ada task sentuh HQ. ✓
- Reuse partialEditProduct/updateItem utk SET. ✓
- Type consistency: pushPhotos return ok|failed|skip; pushMasterContent gabung ke pushed/skipped/failed. ✓
- Gerbang TikTok main_images: T3 verifikasi + flash-jujur. ✓
