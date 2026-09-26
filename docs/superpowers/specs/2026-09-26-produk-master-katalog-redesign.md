# Produk Master — Redesign Katalog (ala Desty, auto by nama)

**Tanggal:** 2026-09-26
**Status:** Design (menunggu review user sebelum writing-plans)
**Modul:** Stok Marketplace / Produk Master E-commerce (Laravel 13 / PHP 8.3).
**⚠️ Ini iterasi UI/UX + konsolidasi** di atas engine "Produk Master E-commerce" yang sudah live (`MarketplaceMasterService`, tabel `marketplace_masters`/`marketplace_master_channels`, `listing.master_id`). Data model inti dipertahankan; yang berubah: cara master dibentuk (dedup by NAMA, bukan per seller_sku), tampilan (katalog bersih ala Desty), + foto/bundle/hapus.

---

## 1. Masalah

Halaman Produk Master sekarang tampil sebagai **daftar "belum termaster" + tombol Tautkan per baris** dan membuat **1 master per seller_sku** — sehingga produk sama yang dijual di TikTok & Shopee (SKU beda) jadi baris terpisah, dan admin harus menautkan manual satu-satu. User mau seperti **Desty Produk Master**: katalog bersih, 1 produk = 1 baris (walau lintas channel), master auto-terisi dari marketplace (bukan tambah manual seperti Desty).

## 2. Keputusan terkunci (dari user)

1. **Master dikonsolidasi by NAMA produk.** Listing dengan nama sama (lintas channel / dalam channel) → **1 master**. Master SKU = SKU perwakilan (listing pertama). Sisa yang namanya beda tapi sebetulnya produk sama → **digabung manual sekali di awal** ("Gabung ke master lain"), setelah itu nempel.
2. **Auto dari TikTok + Shopee** — sekali klik "Siapkan Master", semua listing jadi master. **Bukan tambah manual** tiap produk seperti Desty. Listing baru berikutnya auto-termaster saat Refresh.
3. **Foto**: ditarik otomatis dari gambar listing TikTok/Shopee saat resolve; bisa di-upload/ganti manual dari sistem.
4. **Tab Satuan / Bundle** — bundle **auto-dideteksi dari nama** (mengandung "bundling"/"paket"/"bundle"); bisa di-toggle manual.
5. **Tombol Hapus** master (buang yang salah / tak dipakai).
6. Stok/harga master tetap **terpisah total dari HQ** (tak baca/tulis `hq_stock`/`stock_movements`).

## 3. Perubahan data model

`marketplace_masters` (tambah kolom):
| Kolom | Tipe | Ket. |
|---|---|---|
| `name_key` | string, index | nama ternormalisasi (lower+trim+rapat-spasi) — kunci dedup by nama |
| `is_bundle` | boolean, default false | auto true bila nama match `/bundl|paket/i`; bisa di-toggle |
| `image_url` | string, nullable | URL gambar dari listing marketplace (ditarik saat resolve) |

Foto upload manual: pakai `HasFiles` (koleksi `master_image`) — kalau ada file upload, itu menang atas `image_url`. (Reuse `ImageService` + tabel `files` yang sudah ada, seperti `Product`.)

`master_sku` tetap ada (perwakilan, satu per master). Kolom lain (`base_stock`/`base_price`/`seeded_at`/`product_id`) tetap.

## 4. Pembentukan master (dedup by nama)

- `findOrCreateMaster(sellerSku, name, ?imageUrl)`: cari master by `name_key` (nama ternormalisasi). Bila ada → pakai itu (jangan timpa name/master_sku). Bila belum → buat: `name`, `name_key`, `master_sku=sellerSku`, `is_bundle` = deteksi-dari-nama, `image_url` (bila diberi & master belum punya).
- `upsertListing(...)`: upsert listing by (channel, seller_sku), lalu **kalau `master_id` null**, findOrCreateMaster(by nama) & set `master_id`. (Listing yang sudah termaster/di-merge manual TIDAK diganggu.)
- Efek: TikTok "HANA GLOW FACE MIST 30ml" + Shopee "HANA GLOW FACE MIST 30ml" (nama sama) → 1 master, 2 listing. Nama beda → 2 master (digabung manual).

## 5. Siapkan Master / Rebuild (aksi "Siapkan Master (TikTok+Shopee)")

`siapkanMaster()`:
1. `resolveListings('tiktok')` + `resolveListings('shopee')` — tarik listing terbaru + auto-master by nama + tarik `image_url`.
2. **Re-konsolidasi warisan**: untuk listing yang saat ini termaster ke master "per-SKU" lama (nama-nya ada master lain ber-`name_key` sama), pindahkan ke master by-nama; lalu **hapus master orphan** (tanpa listing). Idempoten.
   - Aman untuk state prod sekarang (master isinya belum ada yang diisi manual berarti; kalau ada `base_stock`/`base_price` ter-set, dipertahankan di master by-nama pertama).
3. Kembalikan ringkasan (found, master, digabung).

> Alternatif migrasi: TIDAK truncate destruktif; rebuild lewat aksi ini (idempoten).

## 6. Gambar dari marketplace

- Saat resolve: ambil URL gambar utama listing. TikTok `searchProducts` → `main_images` (ambil url pertama). Shopee `getItemBaseInfo` → `image.image_url_list[0]`. Simpan ke `master.image_url` saat master dibuat (isi bila kosong; jangan timpa upload manual).
- Verifikasi field respons saat planning dgn baca `MarketplaceMasterService::resolveTiktok/resolveShopee` + client. Kalau field tak ada, `image_url` null (tak fatal).
- Upload manual: aksi "Upload/Ganti Foto" per master (reuse `ImageService::store` ke koleksi `master_image`). Tampilan pakai foto upload → else `image_url` → else placeholder.

## 7. UI — halaman Produk Master (katalog)

Ganti `resources/views/marketplace-stock/index.blade.php` jadi katalog bersih:
- **Header**: judul + tombol **"Siapkan Master (TikTok+Shopee)"**, **"Sinkron semua"**, link **Stok & Harga TikTok / Shopee**.
- **Tabs**: Semua · Satuan · Bundle (filter `is_bundle`; tampilkan jumlah tiap tab).
- **Tabel**: `Foto | Produk (nama + master_sku) | Harga (input) | Stok (input) | Channel Terkait (badge TikTok/Shopee + status kirim stok/harga) | Atur`.
- **Atur (per baris)**: Upload/Ganti Foto · Jadikan Bundle/Satuan (toggle) · Gabung ke master lain · **Hapus** · Sinkron.
- Input Harga/Stok inline (POST) → set master → sinkron (sudah ada `setMasterStock`/`setMasterPrice`/`pushMaster`).
- **Buang** seksi besar "belum termaster" dari tampilan utama. Kalau masih ada listing tanpa master (jarang, edge), tampilkan **banner kecil** "N listing belum termaster — Siapkan Master" (link ke aksi), bukan tabel Tautkan besar.
- Native input; tanpa `@json([...])` literal; form POST + `@csrf`.
- Halaman channel (`/marketplace-stock/{channel}`) tetap (override per-channel) — sesuaikan seperlunya biar konsisten.

## 8. Rute & izin (grup `permission:manage_marketplace_stock`)

Tambahan di atas yang ada:
- `POST /marketplace-stock/siapkan` → `siapkanMaster` (gabung resolve 2 channel + re-konsolidasi + bersihkan orphan). (Bisa menggantikan tombol "Refresh listing" + "Buat master otomatis" lama.)
- `POST /marketplace-stock/master/{master}/foto` → upload/ganti foto.
- `POST /marketplace-stock/master/{master}/bundle` → toggle `is_bundle`.
- `POST /marketplace-stock/master/{master}/gabung` → pindahkan semua listing master ini ke master tujuan (`target_master_id`), lalu hapus master ini bila kosong. (Reuse `tautkanListing` per listing.)
- `DELETE /marketplace-stock/master/{master}` → hapus master (listing-nya jadi `master_id` null via FK nullOnDelete).
- Rute lama set stok/harga/override/ikut-master/push/pushAll tetap.

## 9. Bundle & tab

- Deteksi: `is_bundle = preg_match('/bundl|paket/i', name)` saat master dibuat. Toggle manual override tersimpan (tak ditimpa resolve berikutnya).
- Tab "Bundle" = `where is_bundle`; "Satuan" = `where not is_bundle`; "Semua" = semua.

## 10. Zero-dep, testing

- Zero-dependency; reuse `ImageService`/`HasFiles`. PHPUnit class-style; `Product`/`User` dibuat langsung.
- Unit/Feature: findOrCreateMaster dedup by nama (nama sama → 1 master; beda → 2); is_bundle auto dari nama; upsertListing lintas-channel nama-sama → 1 master 2 listing; siapkanMaster re-konsolidasi + hapus orphan; image_url terisi dari resolve (Http fake); toggle bundle; gabung master (pindah listing + hapus sumber); hapus master (listing jadi unmastered); halaman render katalog + tab filter + akses. HQ tetap tak tersentuh (assert `StockMovement` tak berubah oleh aksi ini bila relevan).

## 11. Out of scope

Push foto ke marketplace, varian bertingkat (parent-varian seperti Desty "Lihat N varian"), fuzzy-name matching (pakai exact/normalized saja; sisanya gabung manual), promo/harga terjadwal, "Duplikat Produk"/"Tambah Produk Baru" manual (master datang dari marketplace, bukan diketik).

## 12. Deploy & risiko

- Deploy: `git pull` + `migrate --force` (tambah 3 kolom di `marketplace_masters`) + `optimize:clear`.
- Risiko: dedup by nama tak menggabung listing yang namanya beda antar-channel → user gabung manual sekali (sesuai kesepakatan). `image_url` dari CDN marketplace bisa kadaluarsa/hotlink-protected → fallback placeholder; upload manual sebagai cadangan andal.
- Re-konsolidasi warisan lewat tombol "Siapkan Master" (idempoten), bukan migrasi destruktif.
