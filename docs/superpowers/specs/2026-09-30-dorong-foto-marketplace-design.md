# Dorong Foto ke Marketplace — Fase 2 (digabung ke "Dorong Konten")

**Tanggal:** 2026-09-30
**Status:** Design DISETUJUI user ("lanjut fase 2" + pilih "gabung ke Dorong Konten")
**Modul:** Stok Marketplace / Produk Master (Laravel 13 / PHP 8.3)
**Lanjutan:** Fase 1 (deskripsi/berat/dimensi) — spec `2026-09-30-dorong-konten-marketplace-design.md`.

## 1. Tujuan & keputusan user

Dorong **FOTO** produk master ke listing TikTok/Shopee yang sudah ada. User memilih **DIGABUNG** ke tombol "Dorong Konten" yang sudah ada (satu tombol kirim deskripsi+berat+dimensi+foto), bukan tombol terpisah.

## 2. ⚠️ Risiko & 4 pengaman (WAJIB)

Dorong foto = **MENGGANTI SELURUH set foto listing** (TikTok & Shopee cuma bisa ganti-semua, tak bisa tambah 1). Pengaman:
1. **Skip kalau master tak punya foto** — set foto listing TAK dikosongkan.
2. **Diff-guard `photo_hash`** — foto hanya benar-benar di-upload+diganti bila set foto master BERUBAH (atau pertama kali). Klik "Dorong Konten" berulang utk update teks TIDAK terus mengganti foto.
3. **`confirm()` diperjelas**: sebut "ganti SEMUA foto listing".
4. **Manual, BUKAN cron** (sama seperti Fase 1).
Urutan foto master dipakai apa adanya (foto pertama = cover/utama).

## 3. Feasibility (terverifikasi + 1 gerbang saat bangun)

Alur 2 langkah tiap platform: **upload multipart → dapat id/uri → set daftar foto**.
- **Shopee (pasti):** `POST /api/v2/media_space/upload_image` (multipart, field `image`) → `response.image_info.image_id`; lalu `update_item` set `image.image_id_list:[…]` (ganti-semua). Reuse `updateItem` (Fase 1).
- **TikTok:** `POST /product/202309/images/upload` (multipart, field `data`, `use_case=MAIN_IMAGE`) → `data.uri`; lalu `partial_edit` set `main_images:[{uri},…]`. Reuse `partialEditProduct` (Fase 1).
  ⚠️ **GERBANG saat bangun:** pastikan `partial_edit` MENERIMA `main_images` (dok resmi JS-rendered, tak bisa dikonfirmasi dari luar). Implementer cek respons `getProduct` asli (bentuk `main_images`) + coba 1 kali; **kalau TikTok tolak main_images via partial_edit → JANGAN diam**: laporkan ke user (mungkin foto TikTok butuh full-edit = tunda), Shopee tetap ship. Desain manual + flash-jujur membuat penolakan TERLIHAT, bukan merusak data.

**Multipart & tanda tangan:** helper JSON lama (`request()`/`shopCall()`) TAK muat file → butuh method upload baru:
- TikTok: sign dengan **body kosong** (multipart dikecualikan dari signature TikTok) + `Http::attach('data', $bytes, $name)` + query app_key/timestamp/sign/shop_cipher + header `x-tts-access-token`.
- Shopee: sign normal (body tak ikut ditandatangani) + `Http::attach('image', $bytes, $name)` + query auth.

**Sumber byte foto:** `$master->filesIn('master_image')->get()` → tiap `File` → `Storage::disk($f->disk ?: 'public')->get($f->path)` (byte) + `$f->mime_type`/`original_name`. Foto sudah di-resize ImageService (≤1280px, masuk rentang TikTok 300–4000px).

## 4. Data model

Migrasi **`000152`** — tambah ke `marketplace_listings` (mirror jejak konten, nullable):
`last_photo_status` (string 16), `last_photo_error` (text), `last_photo_pushed_at` (timestamp), `photo_hash` (string). + `$fillable`.

## 5. Client (2 method baru, multipart)

- `TikTokClient::uploadImage(string $accessToken, ?string $shopCipher, string $bytes, string $filename, string $useCase = 'MAIN_IMAGE'): string` → return `uri`.
- `ShopeeClient::uploadImage(string $accessToken, string $shopId, string $bytes, string $filename): string` → return `image_id`.

## 6. Mesin (`MarketplaceMasterService`)

- `photoHash(MarketplaceMaster $m): string` — `md5(json_encode(<daftar [id,sort_order] foto master terurut>))`. `''`/kosong bila tak ada foto.
- `pushPhotos(MarketplaceListing $l, MarketplaceMaster $m, bool $force): string` (ok|failed|skip) — pola sama pushContent:
  1. `$files = $m->filesIn(MarketplaceMaster::MASTER_IMAGE)->get();` bila kosong → `'skip'`.
  2. `$hash = photoHash($m);` bila `! $force && $l->photo_hash === $hash` → `'skip'`.
  3. `try`: upload tiap file ke channel (urut) → kumpulkan uris/ids → set (tiktok `partialEditProduct(.., ['main_images'=>[['uri'=>…],…]])`; shopee `updateItem((int)item_id, ['image'=>['image_id_list'=>[…]]])`). Sukses → rekam `last_photo_status='ok'`, clear error, `last_photo_pushed_at=now()`, `photo_hash=$hash`; `'ok'`. `catch` → `failed` + `last_photo_error` (potong 500).
- **`pushMasterContent(MarketplaceMaster $m, bool $force = true)` DIUBAH:** per listing jalankan `pushContent` DAN `pushPhotos`, gabung jadi 1 status listing: **failed bila salah satu failed; ok bila salah satu ok; skip bila dua-duanya skip.** Tally 1-listing-1-unit (`pushed/skipped/failed`). (Cron & `pushListing`/`pushDirty` tetap TAK tersentuh.)

## 7. UI

- **Confirm text** (form.blade + index.blade tombol konten) diperbarui: "Kirim & timpa deskripsi/berat/dimensi **dan ganti SEMUA foto** listing di TikTok & Shopee? (field kosong dilewati)". Label tombol → "Dorong konten & foto".
- **channel.blade:** tambah baris status **Foto** (`last_photo_status`/`last_photo_error`) mirror baris Konten.
- Update tes yg meng-assert teks lama (KONFIRMASI di PushContentActionTest; label tombol; ContentStatusDisplay bila perlu).

## 8. Testing (PHPUnit, zero-dep, `Http::fake` + `Storage::fake('public')` + `UploadedFile::fake()->image()`)

- Client upload multipart: hit endpoint benar + kirim file (assert `->post` multipart / `Http::assertSent` body punya file); return uri/image_id dari respons fake.
- `photoHash` deterministik + berubah bila urutan/isi foto berubah.
- `pushPhotos`: skip bila master tanpa foto (TAK ada HTTP); diff-guard (hash sama → skip); ok/failed rekam; force abaikan diff; upload semua foto lalu set (assert jumlah upload = jumlah foto + 1 set-call).
- `pushMasterContent` gabungan: konten ok + foto ok → 1 listing ok; konten ok + foto gagal → listing failed; master tanpa foto → foto skip, konten tetap jalan.
- UI: confirm text baru muncul; channel tampil status foto (ok/gagal + error escaped).
- **HQ tak tersentuh.**

## 9. Deploy & risiko

- Deploy: `git pull` + `migrate --force` (000152) + `optimize:clear`. Cron tak berubah. Scope TikTok `seller.product.write` sudah ada.
- Risiko: (a) ganti-semua foto → dijinakkan skip-empty + diff-guard + confirm; (b) kepatuhan gambar (TikTok min 300px OK; sebagian kategori butuh ≥3 foto / background putih → muncul sbg `failed`, tak merusak); (c) TikTok main_images-via-partial_edit = gerbang verifikasi saat bangun; (d) biaya upload ulang → diff-guard.
- **SMOKE-TEST saat deploy:** dorong 1 produk berfoto → cek foto TikTok & Shopee berubah + status `ok`; ganti foto master → dorong lagi → berubah; dorong lagi tanpa ubah → foto di-skip (diff-guard).
