# Dorong Konten ke Marketplace (Fase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** Dorong konten produk (deskripsi + berat + dimensi) dari Produk Master SKINKU ke listing yang sudah ada di TikTok & Shopee, manual/eksplisit, aman (skip-empty).

**Architecture:** Reuse plumbing signing+JSON (`TikTokClient::request()`, `ShopeeClient::shopCall()`) + pola push per-listing (`pushStock`/`pushPrice`). 2 client method baru (partial update JSON, tanpa multipart), 1 mesin `pushContent` di `MarketplaceMasterService` (jalur TERPISAH dari cron stok/harga), tombol manual + status per-listing.

**Tech Stack:** Laravel 13 / PHP 8.3, PHPUnit class-style, SQLite (tes) / MySQL strict (prod), Tailwind (compiled+committed → styling custom pakai kelas yang sudah ada / inline).

**Spec:** `docs/superpowers/specs/2026-09-30-dorong-konten-marketplace-design.md`

## Global Constraints

- **Zero-dependency:** JANGAN tambah composer/npm. Reuse helper yang ada.
- **HQ ISOLATION:** JANGAN sentuh `products.hq_stock`, `stock_movements`, `InventoryService`. Fitur ini murni `marketplace_listings` + client.
- **Content-push TERPISAH dari cron:** JANGAN ubah `pushListing`/`pushDirty`/`pushEach`/`MarketplacePushStockCommand`/`routes/console.php`. Cron 5-menit tetap HANYA stok+harga.
- **Skip-empty:** field kosong di master TIDAK dikirim (jangan timpa listing dengan kosong).
- **Nama/judul TIDAK didorong.** Foto & barcode & kategori BUKAN scope fase ini.
- **Blade:** form POST + `@csrf`; DELETE `@method('DELETE')`; **TANPA `<form>` nested** (tombol konten = form sendiri di LUAR form edit); TANPA `@json([...])` literal.
- **Permission gate:** route dalam grup `permission:manage_marketplace_stock`; controller boleh andalkan gate route.
- **MySQL strict:** kolom string default, tak ada angka overflow di fitur ini (payload dikirim ke API, bukan disimpan angka besar).
- **Runner:** `C:/php83/php.exe artisan test`. Format: `C:/php83/php.exe vendor/bin/pint --dirty`.
- **Commit attribution:** TANPA trailer AI apa pun (`Co-Authored-By: Claude…` / "Generated with Claude Code") — dilarang CLAUDE.md:10 & AGENTS.md:72. Author = user.

---

### Task 1: Migrasi 000151 + kolom content-sync di MarketplaceListing

**Files:**
- Create: `database/migrations/2026_01_01_000151_add_content_sync_to_marketplace_listings.php`
- Modify: `app/Models/MarketplaceListing.php` (tambah 4 kolom ke `$fillable`)
- Test: `tests/Feature/MarketplaceMaster/ContentSyncMigrationTest.php`

**Interfaces:**
- Produces: kolom `last_content_status` (string 16 nullable), `last_content_error` (text nullable), `last_content_pushed_at` (timestamp nullable), `content_hash` (string nullable) di `marketplace_listings`.

- [ ] **Step 1:** Tulis migrasi. `up()`: `Schema::table('marketplace_listings', function (Blueprint $t) { $t->string('last_content_status', 16)->nullable()->after('last_price_pushed_at'); $t->text('last_content_error')->nullable()->after('last_content_status'); $t->timestamp('last_content_pushed_at')->nullable()->after('last_content_error'); $t->string('content_hash')->nullable()->after('last_content_pushed_at'); });`. `down()`: drop keempatnya.
- [ ] **Step 2:** Tambah `'last_content_status','last_content_error','last_content_pushed_at','content_hash'` ke `$fillable` MarketplaceListing.
- [ ] **Step 3:** Test: setelah migrate, `Schema::hasColumns('marketplace_listings', [...4...])` true; buat listing lalu `update()` keempat kolom → tersimpan.
- [ ] **Step 4:** Run `C:/php83/php.exe artisan test --filter=ContentSyncMigration`; pint; commit.

---

### Task 2: Client method — TikTok partialEditProduct + Shopee updateItem

**Files:**
- Modify: `app/Services/TikTokClient.php` (tambah `partialEditProduct`)
- Modify: `app/Services/ShopeeClient.php` (tambah `updateItem`)
- Test: `tests/Feature/MarketplaceMaster/ContentClientTest.php`

**Interfaces:**
- Consumes: `TikTokClient::request(method,path,accessToken,shopCipher,extraQuery,body)`, `ShopeeClient::shopCall(method,path,accessToken,shopId,params)`.
- Produces:
  - `TikTokClient::partialEditProduct(string $accessToken, ?string $shopCipher, string $productId, array $fields): array`
  - `ShopeeClient::updateItem(string $accessToken, string $shopId, int $itemId, array $fields): array`

- [ ] **Step 1 (test dulu):** `Http::fake()` map endpoint TikTok `*/product/202309/products/PID123/partial_edit` → `{code:0,message:'ok',data:{}}`. Panggil `partialEditProduct($tok,$cipher,'PID123',['description'=>'Halo'])`. Assert `Http::assertSent` URL mengandung `/product/202309/products/PID123/partial_edit` DAN body (dari `request()`) memuat `description`. (Ikuti pola tes client TikTok yang sudah ada untuk config app_key/secret + token.)
- [ ] **Step 2:** Implement `partialEditProduct`: `return $this->request('POST', "/product/202309/products/{$productId}/partial_edit", $accessToken, $shopCipher, [], $fields);`
- [ ] **Step 3 (test dulu):** `Http::fake()` Shopee `*/api/v2/product/update_item*` → `{error:'',message:'',response:{}}`. Panggil `updateItem($tok,'SHOP1',555,['description'=>'Halo','weight'=>0.25])`. Assert URL `/api/v2/product/update_item` DAN params memuat `item_id=555` + `description` + `weight`.
- [ ] **Step 4:** Implement `updateItem`: `return $this->shopCall('POST', '/api/v2/product/update_item', $accessToken, $shopId, array_merge(['item_id' => $itemId], $fields));`
- [ ] **Step 5:** Run `--filter=ContentClient`; pint; commit.

---

### Task 3: Mesin content-push di MarketplaceMasterService

**Files:**
- Modify: `app/Services/MarketplaceMasterService.php`
- Test: `tests/Feature/MarketplaceMaster/PushContentTest.php`

**Interfaces:**
- Consumes: `MarketplaceMaster` (field `description`,`weight_g`,`length_cm`,`width_cm`,`height_cm`), `MarketplaceListing` (`channel`,`item_id`,`content_hash`), conn/token helpers, `tallyPush()`, client method Task 2.
- Produces (public): `buildContentPayload(MarketplaceMaster $m, string $channel): array`, `pushContent(MarketplaceListing $l, MarketplaceMaster $m, bool $force): string` (return `ok|failed|skip`), `pushMasterContent(MarketplaceMaster $m, bool $force = true): array`.
- Private: `contentHash(array $payload): string`.

- [ ] **Step 1 (test):** `buildContentPayload` — master `description='Serum X', weight_g=250, length_cm=10,width_cm=8,height_cm=5`:
  - channel `tiktok` → `['description'=>'Serum X','package_weight'=>['value'=>'0.25','unit'=>'KILOGRAM'],'package_dimensions'=>['length'=>'10','width'=>'8','height'=>'5','unit'=>'CENTIMETER']]`.
  - channel `shopee` → `['description'=>'Serum X','weight'=>0.25,'dimension'=>['package_length'=>10,'package_width'=>8,'package_height'=>5]]`.
  - master kosong semua → `[]` (kedua channel).
  - `weight_g=250` tapi dimensi ada yang 0/null → payload TANPA `package_dimensions`/`dimension` tapi tetap ada weight.
- [ ] **Step 2:** Implement `buildContentPayload` sesuai §6 spec: hanya masukkan field non-kosong; deskripsi masuk bila `trim((string)$m->description) !== ''`; weight bila `(int)$m->weight_g > 0` (konversi `round($m->weight_g/1000,3)`; TikTok string, Shopee float); dimensi hanya bila `length_cm>0 && width_cm>0 && height_cm>0`.
- [ ] **Step 3 (test):** `contentHash` deterministik (payload sama → hash sama).
- [ ] **Step 4:** Implement `contentHash`: `return md5(json_encode($payload));`
- [ ] **Step 5 (test):** `pushContent` —
  - payload kosong → return `'skip'`, `Http::assertNothingSent()`.
  - `Http::fake` sukses, listing tiktok item_id set + koneksi TikTok ada (buat row koneksi seperti tes push stok/harga yang sudah ada) → return `'ok'`, `last_content_status='ok'`, `content_hash` terisi, `last_content_pushed_at` non-null.
  - panggil lagi tanpa `force` (hash sama) → `'skip'` (diff-guard), tak kirim HTTP.
  - `force=true` walau hash sama → kirim lagi (`'ok'`).
  - `Http::fake` error/throw → return `'failed'`, `last_content_status='failed'`, `last_content_error` terisi.
  - channel shopee → panggil `updateItem`.
- [ ] **Step 6:** Implement `pushContent` sesuai §6 spec (null/diff-guard → cabang channel → rekam ok/failed). Reuse `tiktokConn/tiktokToken` (+`shop_cipher`) & `shopeeConn/shopeeToken` (+`shop_id`) persis seperti `pushStock`.
- [ ] **Step 7 (test):** `pushMasterContent` — master dgn 2 listing (tiktok+shopee) item_id set, `Http::fake` sukses → `['pushed'=>2,'skipped'=>0,'failed'=>0]`. HQ (`stock_movements`) tak berubah.
- [ ] **Step 8:** Implement `pushMasterContent`: iterasi `$m->listings()->whereNotNull('item_id')->get()`, `$this->tallyPush($out, $this->pushContent($l, $m, $force))`, return `$out` (init `['pushed'=>0,'skipped'=>0,'failed'=>0]`).
- [ ] **Step 9:** Pastikan `pushListing`/`pushDirty`/`pushEach` TAK diubah. Run `--filter=PushContent`; pint; commit.

---

### Task 4: Controller + route + tombol UI

**Files:**
- Modify: `app/Http/Controllers/MarketplaceStockController.php` (tambah `pushContent`)
- Modify: `routes/web.php` (route `marketplace-stock.master.konten` dalam grup permission)
- Modify: `resources/views/marketplace-stock/form.blade.php` (tombol di halaman Ubah, form sendiri di LUAR form edit)
- Modify: `resources/views/marketplace-stock/index.blade.php` (tombol di menu Atur per master)
- Test: `tests/Feature/MarketplaceMaster/PushContentActionTest.php`

**Interfaces:**
- Consumes: `MarketplaceMasterService::pushMasterContent`, `MarketplaceStockController::pushFlash(RedirectResponse,array,string)`.
- Produces: route name `marketplace-stock.master.konten` (POST, `{master}`).

- [ ] **Step 1:** Controller `pushContent(MarketplaceMaster $master, MarketplaceMasterService $svc): \Illuminate\Http\RedirectResponse { $r = $svc->pushMasterContent($master); return $this->pushFlash(back(), $r, 'Konten'); }`.
- [ ] **Step 2:** Route (dalam grup `permission:manage_marketplace_stock`, dekat route master lain): `Route::post('marketplace-stock/master/{master}/konten', [MarketplaceStockController::class, 'pushContent'])->name('marketplace-stock.master.konten');`. (Cek nama binding `{master}` = `MarketplaceMaster` seperti route master lain.)
- [ ] **Step 3:** `form.blade` (halaman Ubah, `$master->exists`): tambah **form sendiri DI LUAR `<form>` edit utama** (mis. di dekat tombol Simpan tapi bukan di dalamnya — taruh setelah `</form>` utama, atau di kartu terpisah): `<form method="POST" action="{{ route('marketplace-stock.master.konten', $master) }}" onsubmit="return confirm('Kirim & timpa deskripsi/berat/dimensi produk ini di TikTok & Shopee? (field kosong dilewati)')">@csrf<button class="px-4 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 text-sm">Dorong Konten ke Marketplace</button></form>`. Beri keterangan kecil "Hanya deskripsi, berat, dimensi. Foto belum.". **Pastikan tak nested** (verifikasi pola form.blade sekarang: galeri foto sudah di luar form utama).
- [ ] **Step 4:** `index.blade` menu Atur per master: tambah item/tombol serupa (form POST `konten` + confirm). Ikuti pola tombol Atur yang ada (Ubah/Duplikat/Hapus).
- [ ] **Step 5 (test):** admin POST `konten` untuk master (mock `Http::fake` sukses / listing tanpa item_id) → redirect back + session flash `status` memuat hitungan; service kepanggil. Non-izin (reseller) → **403**. Render halaman Ubah memuat action route `konten` (assertSee route, false) + form konten TAK bikin nested (cek kedalaman form maks 1 di `<main>`, pola seperti `test_form_edit_tak_ada_form_bersarang`).
- [ ] **Step 6:** Run `--filter=PushContentAction`; pint; commit.

---

### Task 5: Status konten per-listing di channel.blade + dokumentasi

**Files:**
- Modify: `resources/views/marketplace-stock/channel.blade.php` (tampil `last_content_status`/`last_content_error`)
- Modify: `docs/SISTEM.md` + `docs/PETA-SISTEM.md` (catat fitur content-push Fase 1)
- Test: `tests/Feature/MarketplaceMaster/ContentStatusDisplayTest.php`

**Interfaces:**
- Consumes: kolom `last_content_status`/`last_content_error` di listing (Task 1), pola tampil status stok/harga yang sudah ada di `channel.blade`.

- [ ] **Step 1:** Di `channel.blade`, di baris/kartu tiap listing, tambah tampil status konten: bila `$l->last_content_status` ada → tampilkan label "Konten: ok/gagal" (gagal = merah) + `$l->last_content_error` bila ada. Ikuti markup status stok/harga yang sudah ada (jangan bikin komponen baru).
- [ ] **Step 2 (test):** listing dgn `last_content_status='failed', last_content_error='Deskripsi ditolak'` → halaman channel `assertSee('Deskripsi ditolak')` + penanda gagal. Listing `ok` → `assertSee` penanda ok konten.
- [ ] **Step 3:** Update `docs/SISTEM.md` (seksi Stok Marketplace) + `docs/PETA-SISTEM.md`: catat "Dorong Konten Fase 1 (deskripsi/berat/dimensi, Shopee+TikTok, manual, skip-empty; foto=Fase 2)".
- [ ] **Step 4:** Run `--filter=ContentStatusDisplay`; full suite `C:/php83/php.exe artisan test`; pint; commit.

---

## Self-Review

- Spec coverage: kolom (T1) · client (T2) · mesin skip-empty+diff-guard+units (T3) · tombol+route+controller+confirm (T4) · status display+docs (T5). ✓
- Aturan aman: skip-empty (T3 buildContentPayload), manual/no-cron (T3 jalur terpisah, tak sentuh pushDirty), no nama/judul (T3 payload), confirm (T4). ✓
- HQ isolation: tak ada task sentuh stock_movements/InventoryService. ✓
- Nested-form: T4 Step 3/5 eksplisit. ✓
- Type consistency: `pushContent` return string ok|failed|skip; `pushMasterContent`/`pushFlash` pakai `['pushed','skipped','failed']` sama seperti stok/harga. ✓
