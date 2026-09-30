# Dorong Konten ke Marketplace — Fase 1 (deskripsi + berat + dimensi)

**Tanggal:** 2026-09-30
**Status:** Design DISETUJUI user ("jalankan ini")
**Modul:** Stok Marketplace / Produk Master E-commerce (Laravel 13 / PHP 8.3)

## 1. Tujuan & scope

Stok & harga sudah tersinkron ke TikTok/Shopee. Fase ini menyambungkan **konten produk** (yang diketik di form Produk Master) supaya ikut terdorong ke listing yang **SUDAH ADA** di marketplace.

**Scope Fase 1 (dipilih user):** **deskripsi + berat + dimensi**, ke **Shopee + TikTok** sekaligus.

**DI LUAR scope (jujur, ditunda):**
- **Foto** = Fase 2 (butuh upload multipart — endpoint `images/upload` TikTok & `media_space` Shopee tak muat plumbing JSON sekarang).
- **Nama/judul** TIDAK didorong (identitas listing, rawan salah timpa).
- **Barcode & kategori** tak ikut fase ini.
- **Buat listing baru** dari SKINKU = proyek terpisah (kategori+atribut wajib+BPOM+review app).

## 2. Feasibility (terverifikasi ke dokumen resmi)

Dua-duanya **partial update JSON** — muat plumbing `request()`/`shopCall()` yang sudah ada, **tanpa multipart**:
- **TikTok:** `POST /product/202309/products/{product_id}/partial_edit` — update sebagian (deskripsi/`package_weight`/`package_dimensions`) tanpa kirim ulang kategori/atribut → tak kebentur BPOM untuk edit. Butuh scope `seller.product.write` (SUDAH diaktifkan + re-auth waktu benerin stok/harga). App = **Skinku_Detail** (`services.tiktok`), TAK perlu app baru.
- **Shopee:** `POST /api/v2/product/update_item` — field selain `item_id` opsional (partial). App toko Shopee yang sudah terhubung.

⚠️ **Saat bangun:** key payload TikTok diverifikasi dari respons `getProduct` asli (bukan nebak) sebelum kirim, biar tak gagal di prod (MySQL/aturan TikTok strict).

## 3. 3 Aturan aman (WAJIB, default disetujui user)

1. **Skip field kosong** — field deskripsi/berat/dimensi yang kosong di master **TIDAK dikirim** (tak akan menimpa listing bagus dengan kosong). Kalau semua kosong utk channel itu → `skip`, tak ada panggilan API.
2. **Manual/eksplisit, BUKAN auto-cron** — beda dari stok/harga. Tombol **"Dorong Konten ke Marketplace"** per produk + `confirm()`. **Cron 5-menit tetap HANYA stok+harga** (content-push TIDAK masuk `pushDirty`/`pushListing`/`pushEach`).
3. **Nama/judul TIDAK didorong.**

## 4. Data model

Migrasi **`000151`** — tambah ke `marketplace_listings` (mirror jejak stok/harga, semua nullable):
| Kolom | Tipe | Ket. |
|---|---|---|
| `last_content_status` | string(16), nullable | `ok` \| `failed` |
| `last_content_error` | text, nullable | pesan error asli (dipotong 500) |
| `last_content_pushed_at` | timestamp, nullable | waktu push terakhir |
| `content_hash` | string, nullable | hash payload terkirim (diff-guard) |

Model `MarketplaceListing`: tambah keempat kolom ke `$fillable`.

## 5. Client (2 method baru, JSON — reuse helper yang ada)

- `TikTokClient::partialEditProduct(string $accessToken, ?string $shopCipher, string $productId, array $fields): array`
  → `$this->request('POST', "/product/202309/products/{$productId}/partial_edit", $accessToken, $shopCipher, [], $fields)`.
- `ShopeeClient::updateItem(string $accessToken, string $shopId, int $itemId, array $fields): array`
  → `$this->shopCall('POST', '/api/v2/product/update_item', $accessToken, $shopId, array_merge(['item_id' => $itemId], $fields))`.

## 6. Mesin (`MarketplaceMasterService`)

- `buildContentPayload(MarketplaceMaster $m, string $channel): array` — payload partial per-channel, **hanya field non-kosong** (skip-empty), sudah konversi satuan. `[]` bila tak ada yang dikirim.
  - **TikTok:** `description` (bila teks non-kosong; string HTML/plain); `package_weight` = `['value' => (string) round($m->weight_g/1000, 3), 'unit' => 'KILOGRAM']` (bila `weight_g > 0`); `package_dimensions` = `['length'=>(string)$m->length_cm,'width'=>(string)$m->width_cm,'height'=>(string)$m->height_cm,'unit'=>'CENTIMETER']` (**hanya bila ketiga dimensi > 0**).
  - **Shopee:** `description` (bila non-kosong); `weight` = `round($m->weight_g/1000, 3)` (float kg, bila `weight_g>0`); `dimension` = `['package_length'=>$m->length_cm,'package_width'=>$m->width_cm,'package_height'=>$m->height_cm]` (int cm, **hanya bila ketiga > 0**).
- `contentHash(array $payload): string` — `md5(json_encode($payload))`, buat diff-guard.
- `pushContent(MarketplaceListing $l, MarketplaceMaster $m, bool $force): string` — pola sama persis `pushStock`/`pushPrice`:
  1. `$payload = buildContentPayload($m, $l->channel);` bila `[]` → `return 'skip'`.
  2. `$hash = contentHash($payload);` bila `! $force && $l->content_hash === $hash` → `return 'skip'`.
  3. `try`: cabang channel → panggil client (tiktok: `partialEditProduct(token,cipher,$l->item_id,$payload)`; shopee: `updateItem(token,shopId,(int)$l->item_id,$payload)`). Sukses → `$l->update(['last_content_status'=>'ok','last_content_error'=>null,'last_content_pushed_at'=>now(),'content_hash'=>$hash])`; `return 'ok'`. `catch(\Throwable $e)` → `$l->update(['last_content_status'=>'failed','last_content_error'=>mb_substr($e->getMessage(),0,500)])`; `return 'failed'`.
- `pushMasterContent(MarketplaceMaster $m, bool $force = true): array` — iterasi `$m->listings()->whereNotNull('item_id')->get()`, `tallyPush()` per hasil, return `['pushed'=>,'skipped'=>,'failed'=>]`.
- **JANGAN** sentuh `pushListing`/`pushDirty`/`pushEach`/cron — content-push jalur terpisah (aturan aman #2).
- Reuse `tiktokConn/tiktokToken/shopeeConn/shopeeToken`, `tallyPush`.

## 7. Controller + Route + UI

- `MarketplaceStockController::pushContent(MarketplaceMaster $master, MarketplaceMasterService $svc): RedirectResponse` → `$r = $svc->pushMasterContent($master); return $this->pushFlash(back(), $r, 'Konten');` (reuse `pushFlash` — flash jujur X terkirim/Y dilewati/Z gagal + error).
- **Route** (grup `permission:manage_marketplace_stock`): `POST /marketplace-stock/master/{master}/konten` → name `marketplace-stock.master.konten`.
- **UI tombol** "Dorong Konten ke Marketplace" (form POST sendiri + `onsubmit="return confirm('Kirim & timpa deskripsi/berat/dimensi produk ini di TikTok & Shopee?')"`):
  - Di halaman **Ubah master** (`form.blade`) — **WAJIB `<form>` sendiri DI LUAR form edit utama** (HTML larang nested form).
  - Di **katalog** (`index.blade`) menu **Atur** per master.
- **Status per-listing** di `channel.blade`: tampilkan `last_content_status`/`last_content_error` (warnai `failed` merah) — pola sama seperti status stok/harga yang sudah ada.

## 8. Testing (PHPUnit class-style, zero-dep, `Http::fake()`)

- Client: `partialEditProduct`/`updateItem` hit endpoint + body benar (assert URL + payload).
- `buildContentPayload`: skip-empty (master kosong → `[]`); konversi 250g → `'0.25'`/`0.25`; dimensi hanya bila ketiga ada; deskripsi kosong tak masuk.
- `pushContent`: skip bila payload kosong (TAK panggil HTTP); diff-guard (hash sama → skip); `ok`/`failed` terekam per channel; force abaikan diff-guard.
- `pushMasterContent`: tally benar.
- Controller: tombol → service kepanggil + flash jujur; non-izin **403**; content-push TAK terpicu cron/`pushDirty`.
- **HQ tak tersentuh** (murni listing + client, tak sentuh `stock_movements`/`InventoryService`).

## 9. Deploy & risiko

- Deploy: `git pull` + `migrate --force` (000151) + `optimize:clear`. Cron tak berubah.
- Zero-dependency. Reuse plumbing + pola stok/harga.
- Risiko utama = timpa listing → dijinakkan aturan aman (skip-empty + manual + confirm + tanpa nama/foto).
- Ekspektasi jelas: hanya deskripsi/berat/dimensi; foto & konten lain nyusul.
