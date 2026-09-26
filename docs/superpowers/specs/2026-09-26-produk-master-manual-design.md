# Produk Master E-commerce — Model MANUAL (ala Desty)

**Tanggal:** 2026-09-26
**Status:** Design (disetujui user — "gas")
**Modul:** Stok Marketplace / Produk Master E-commerce (Laravel 13 / PHP 8.3)
**⚠️ PIVOT:** membalik model "auto dari marketplace" (Fase 2/2b). Sekarang master **dibuat MANUAL** oleh admin (seperti Desty Produk Master), lalu **ditautkan** ke listing TikTok/Shopee yang sudah ada untuk sinkron stok. **Mesin sinkron yang sudah ada dipakai ulang, tidak diubah.**

---

## 1. Latar & keputusan terkunci (dari user)

Halaman Produk Master versi auto (dedup by nama, "Siapkan Master") menghasilkan katalog berantakan (banyak baris "stok unmapped / harga —"). User memutuskan:

1. **Kosongkan semua** master → mulai bersih.
2. **Master dibuat MANUAL** lewat tombol **"+ Tambah Produk Baru"** (persis Desty). Tidak ada lagi auto-buat master dari marketplace.
3. **Tetap sinkron stok**: tiap master bisa **ditautkan** ke listing TikTok & Shopee yang sudah ada → stok master = 1 angka yang otomatis kepotong saat order + dikirim ke kedua channel. (Jawaban user: "Tetap sinkron (master → tautkan ke listing)".)
4. Menu **"Tambah ke Marketplace"** = **tautkan ke listing yang SUDAH ada** (bukan bikin listing baru di marketplace). (Jawaban user.)
5. Tampilan & menu **persis Desty** (gambar 2 & 3).
6. **HQ tetap tidak disentuh** (`products` / `hq_stock` / `stock_movements` / `InventoryService`).

## 2. Yang TIDAK berubah (dipakai ulang apa adanya)

Mesin sinkron `MarketplaceMasterService` tetap:
`effectiveStock/effectivePrice`, `setMasterStock/setMasterPrice`, `setChannelStock/setChannelPrice`, `ikutMaster`, `pushListing/pushMaster/pushAll/pushDirty`, `applyOrderDelta` (potong stok master saat order, HQ tak disentuh), `resolveListings/resolveTiktok/resolveShopee` (refresh cache listing dari channel), `findOrCreateMaster` (dipakai jalur tautkan-buat-baru), `tautkanListing`. Cron `marketplace:push-stock` (pakai `pushDirty`) tetap. Tabel `marketplace_masters` / `marketplace_master_channels` / `marketplace_listings` tetap — **tanpa perubahan skema** (semua kolom sudah ada).

## 3. Yang DIHAPUS (bertentangan dg model manual / berbahaya)

- **Auto-buat master di `upsertListing`** — refresh listing sekarang HANYA upsert baris listing (biarkan `master_id` null untuk listing baru). (Kalau tidak: tiap refresh bikin master otomatis lagi → berantakan.)
- **`siapkanMaster()`** + route/aksi `siapkan` + tombol "Siapkan Master".
- **`deleteOrphanMasters()`** — **BERBAHAYA di model manual**: master baru yang belum ditautkan itu SAH; menghapus master tanpa listing akan menghapus produk yang baru dibuat admin.
- **`seedFromTiktok()`** + route/aksi `seed` + tombol "Tarik Stok Awal" (auto-buat master + set stok).
- **`masterizeUnmastered()`** + route/aksi `masterizeAll` + `MasterizeTest`.
- **`mergeMaster()`** + route/aksi `gabung` (tak ada di menu Desty) — dedup by nama sudah tidak dipakai.
- Aksi **push per-baris** (`push/{master}`) dari UI (cukup "Sinkron semua" + auto-push saat set stok/harga & cron). Route push per-master dihapus.
- Standalone **`uploadFoto`** endpoint — foto ditangani langsung di form Tambah/Ubah.

Tes yang gugur bersamanya: `SiapkanMasterTest`, `SeedMasterTest`, `MasterizeTest` (dihapus); `ResolveMasterTest` (diubah: assert listing ter-upsert TAPI **tidak** auto-termaster); bagian gabung di `MasterActionsTest` (dihapus).

## 4. Yang DITAMBAH

### 4.1 Buat master manual
- Controller `create()` (tampil form) + `store()` (simpan). Service tak wajib; buat langsung `MarketplaceMaster::create()`.
- Field form: **Nama** (required), **Master SKU** (required), **Harga** (nullable, numeric ≥0), **Stok** (nullable, integer ≥0), **Tipe** (Satuan/Bundle — radio/select → `is_bundle`), **Foto** (nullable image ≤5MB → `ImageService::attach` koleksi `master_image`).
- `name_key` di-set dari `MarketplaceMaster::normalizeName(name)` (untuk cari; **tanpa** enforce dedup — admin boleh nama sama).
- Stok/harga tersimpan ke `base_stock`/`base_price` (via `setMasterStock`/`setMasterPrice` bila diisi, supaya `seeded_at` ke-set untuk guard `applyOrderDelta`).

### 4.2 Ubah master
- `edit()` + `update()` — sama field dg create; foto opsional (ganti bila di-upload). Update `name`/`name_key`/`master_sku`/`is_bundle`, dan stok/harga lewat setter yang sama.

### 4.3 Duplikat master
- `duplicate()` → `duplicateMaster(MarketplaceMaster): MarketplaceMaster` di service: clone `name` ("{name} (copy)"), `master_sku` ("{sku}-COPY"), `is_bundle`, `base_price`; **tanpa** listing, channel-override, foto, atau `base_stock` (mulai bersih). Redirect ke edit hasil duplikat.

### 4.4 Tambah ke Marketplace (tautkan ke listing existing)
- Per master: pilih **channel** + **listing yang belum tertaut** (native `<select>`, dikelompokkan/di-filter per channel) → POST ke aksi `tautkan` yang SUDAH ada dg `listing_id` + `master_id` = master ini (jalur `masterId != null` di `tautkanListing`, tak buat master baru).
- Sumber pilihan: `MarketplaceListing::whereNull('master_id')` (opsional yang tertaut master lain bisa dipindah — untuk MVP cukup yang belum tertaut). Butuh listing ter-refresh → tombol **"Refresh Listing"** (aksi `resolve` yang sudah ada, tanpa auto-master).

## 5. UI — halaman katalog (kolom persis Desty gambar 2)

`resources/views/marketplace-stock/index.blade.php` ditulis ulang:
- **Header**: tombol utama **"+ Tambah Produk Baru"**, **"Sinkron semua"** (`push-all`), **"Refresh Listing"** (`resolve`), link **Stok TikTok / Shopee**.
- **Tabs**: Semua · Satuan · Bundle (filter `is_bundle` + jumlah).
- **Tabel**: `Foto | Informasi Produk (nama) | Master SKU | Harga | Stok | Produk Terkait | Toko Terkait | Atur`.
  - **Foto**: `master->imageUrl()` else placeholder.
  - **Harga/Stok**: input inline per baris (form kecil POST ke `master.harga`/`master.stok` yang sudah ada → set + auto-push), placeholder "—" bila null. Juga bisa diubah lewat form Ubah.
  - **Produk Terkait** = jumlah listing tertaut (`listings->count()`); **Toko Terkait** = jumlah channel unik (`listings->pluck('channel')->unique()->count()`). "—" bila 0.
- **Atur (dropdown per baris, persis gambar 3)**: **Ubah** · **Duplikat Produk** · **Tambah ke Marketplace** (buka pemilih listing) · **Jadikan Bundle/Satuan** (toggle) · **Hapus**.
- **Empty state**: "Belum ada produk master. Klik + Tambah Produk Baru." (tanpa tabel "belum termaster" besar).
- Native input; tanpa `@json([...])` literal; form POST + `@csrf`; DELETE via `@method('DELETE')`; foto form `enctype="multipart/form-data"`.
- Halaman channel (`/marketplace-stock/{channel}`) tetap (override per-channel) — sesuaikan bila perlu.

## 6. Rute & izin (grup `permission:manage_marketplace_stock`)

Tambah: `GET .../create` (create), `POST .../` atau `.../store` (store), `GET .../master/{master}/edit` (edit), `PUT .../master/{master}` (update), `POST .../master/{master}/duplikat` (duplicate).
Tetap: `index`, `channel`, `master.stok`, `master.harga`, `channel.stok`, `channel.harga`, `ikut-master`, `tautkan`, `push-all`, `resolve`, `master.hapus`, `master.bundle`.
Hapus: `siapkan`, `seed-tiktok`, `masterize-all`, `gabung`, `push/{master}`, `master.foto`.

## 7. Data awal (kosongkan semua)

Tidak perlu migrasi baru. Migrasi reset `000147` (sudah ke-merge di main) mengosongkan master e-commerce saat deploy; karena model manual tidak auto-buat master, katalog tetap kosong sampai admin isi. (Lokal: master sisa uji bisa diabaikan; prod kosong setelah pull berikutnya.) **HQ tak tersentuh.**

## 8. Alur pemakaian setelah deploy

1. **Refresh Listing** → cache listing TikTok/Shopee terisi (semua `master_id` null).
2. **+ Tambah Produk Baru** → isi nama/SKU/harga/stok/tipe/foto.
3. Atur → **Tambah ke Marketplace** → pilih listing TikTok + Shopee → tertaut.
4. Stok tersinkron otomatis (order potong `applyOrderDelta`, cron push tiap 5mnt, "Sinkron semua" untuk dorong manual).

## 9. Zero-dep & testing

- Zero-dependency; reuse `ImageService`/`HasFiles`. PHPUnit class-style; `Product`/`User` dibuat langsung; `Http::fake` untuk API; `Storage::fake('public')` + `UploadedFile::fake()->image()` untuk foto.
- Tes baru: create master (+foto), edit, duplicate (tanpa listing/stok), tautkan ke listing existing (link, bukan buat master), refresh listing TIDAK auto-master, halaman katalog render kolom Desty + tab + empty-state + akses ditolak non-izin, HQ tak berubah (assert `StockMovement`/`hq_stock` tetap saat aksi master).
- Tes lama: hapus `SiapkanMasterTest`/`SeedMasterTest`/`MasterizeTest`; sesuaikan `ResolveMasterTest`, `MasterActionsTest`, `CatalogPageTest`, `DedupByNameTest` (findOrCreateMaster masih ada untuk jalur tautkan-buat).

## 10. Out of scope

Bikin listing baru di marketplace (Tambah Produk → marketplace), varian bertingkat (parent-varian "Lihat N varian" Desty), Kelola Harga/Gambar terpisah (foto cukup di form), import massal, fuzzy-name.

## 11. Deploy & risiko

- Deploy: `git pull` + `migrate --force` (tak ada migrasi baru dari fitur ini; hanya 000147/148 yang sudah ke-merge) + `optimize:clear`.
- Risiko: admin harus input manual (≈21 produk — dapat diterima). Foto CDN marketplace tak lagi auto-tarik → upload manual (andal). Listing harus di-refresh dulu sebelum bisa ditautkan.
