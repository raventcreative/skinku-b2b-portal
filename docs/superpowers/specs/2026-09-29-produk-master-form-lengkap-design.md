# Produk Master — Form Lengkap ala Desty (field internal)

**Tanggal:** 2026-09-29
**Status:** Design (disetujui user — "ok gas")
**Modul:** Stok Marketplace / Produk Master E-commerce (Laravel 13 / PHP 8.3)
**⚠️ Scope:** perkaya FORM Produk Master jadi lengkap seperti Desty (kategori, deskripsi, foto banyak, berat, dimensi, barcode). Data tersimpan di SKINKU sebagai master produk lengkap. **BUKAN** push konten ke marketplace (itu Fase 4 berat — kategori/atribut/BPOM/review). Harga & stok tetap push seperti sekarang (tak diubah).

---

## 1. Latar & keputusan (dari user)

User lihat form "Ubah Produk" Desty (Informasi Produk: nama/kategori/deskripsi; Informasi Penjualan: harga/stok/master SKU/barcode; Foto sampai 9; Pengiriman: berat/dimensi). Minta form yang sama di SKINKU "biar bisa input & ubah". Dikonfirmasi:
- Form **LIVE & permanen di SKINKU** (bisa input/simpan/ubah, data tersimpan).
- **Harga & Stok tetap push ke TikTok/Shopee** (mekanisme lama, tak diubah).
- **Field baru (kategori/deskripsi/foto/berat/dimensi/barcode) DISIMPAN di SKINKU, TIDAK di-push ke marketplace** — push konten produk = Fase 4 (butuh mapping kategori+atribut wajib+BPOM+upload media+scope+app review). Di luar scope ini; bisa nyusul.
- **Tidak menambah menu baru** — perkaya form "Tambah Produk Baru"/"Ubah" yang sudah ada di Stok Marketplace → Stok Master.

## 2. Perubahan data model

`marketplace_masters` (migrasi **`000150`** — tambah kolom, semua nullable):
| Kolom | Tipe | Ket. |
|---|---|---|
| `category` | string, nullable | label kategori (mis. "Perawatan Wajah / BB Cream") — teks bebas, BUKAN pohon kategori marketplace |
| `description` | text, nullable | deskripsi produk |
| `weight_g` | unsignedInteger, nullable | berat (gram) |
| `length_cm` | unsignedInteger, nullable | panjang (cm) |
| `width_cm` | unsignedInteger, nullable | lebar (cm) |
| `height_cm` | unsignedInteger, nullable | tinggi (cm) |
| `barcode` | string, nullable | barcode/GTIN |

Foto banyak: pakai koleksi **`master_image`** (trait `HasFiles`, SUDAH multi-file: `filesIn`/`fileGallery`/`fileUrls`/`firstFileUrl`). Sekarang cuma dipakai 1 foto; jadikan **sampai 9**. `imageUrl()` = `firstFileUrl(master_image)` = foto utama (tetap). Tambah kemampuan: upload banyak (append), hapus per-foto, jadikan foto utama.

Model `MarketplaceMaster`: `$fillable` += category/description/weight_g/length_cm/width_cm/height_cm/barcode. Tak perlu cast khusus (string/int).

## 3. Form (perkaya `resources/views/marketplace-stock/form.blade.php`)

Susun mirip Desty, 3 seksi:
- **Informasi Produk:** Nama (ada) · **Kategori** (baru) · **Deskripsi** (baru, textarea)
- **Informasi Penjualan:** Harga (ada) · Stok (ada) · Master SKU (ada) · Tipe Satuan/Bundle (ada) · **Barcode** (baru)
- **Foto Produk:** galeri **sampai 9** (baru — upload banyak; tampil thumbnail; hapus per-foto; tandai foto utama). Foto pertama = utama.
- **Pengiriman:** **Berat** (gram, baru) · **Dimensi** P×L×T cm (baru)

Native input; form POST + `@csrf`; `enctype="multipart/form-data"`; foto input `name="foto[]" multiple`. Tanpa `@json([...])` literal.

## 4. Foto: upload banyak + hapus + utama

- **Upload:** input `foto[]` multiple → tiap file `ImageService::attach($master, $file, MarketplaceMaster::MASTER_IMAGE)` (validasi tiap file `image|max:5120`; total maks 9 → tolak bila melebihi).
- **Hapus per-foto:** `DELETE /marketplace-stock/master/{master}/foto/{file}` → hapus baris `File` (dan file fisik via model File delete). Guard: file wajib milik master ini + koleksi `master_image`.
- **Jadikan utama:** `POST /marketplace-stock/master/{master}/foto/{file}/utama` → set `sort_order` file itu jadi paling kecil (0), file lain digeser — supaya `filesIn` (order by sort_order) menaruhnya pertama = `imageUrl()`.
- Galeri di form edit: tampil `fileGallery(master_image)` (id+url) tiap foto dengan tombol Hapus + Jadikan Utama; foto pertama diberi badge "Utama".

## 5. Yang TIDAK berubah / TIDAK termasuk

- **Push ke marketplace:** cuma stok & harga (`pushMaster`/cron), TAK diubah. Field baru **tak** di-push.
- Tabel katalog `index.blade` (foto utama + kolom sekarang) tetap; boleh nambah info kecil bila perlu, tapi bukan fokus.
- Modal Kaitkan, Kosongkan, dll. tak disentuh.
- Tak ada menu sidebar baru.

## 6. Rute (grup `permission:manage_marketplace_stock`)

Tambah: `DELETE .../master/{master}/foto/{file}` (hapus foto), `POST .../master/{master}/foto/{file}/utama` (jadikan utama). Store/update yang sudah ada diperkaya (terima field baru + `foto[]`). Rute lain tetap.

## 7. Testing (PHPUnit class-style, zero-dep)

- store/update simpan field baru (category/description/weight_g/dimensi/barcode) — assert tersimpan.
- upload `foto[]` banyak → beberapa File di koleksi master_image; melebihi 9 ditolak.
- hapus foto → File hilang (guard: foto master lain / koleksi lain ditolak 404).
- jadikan utama → `imageUrl()` berubah ke foto terpilih.
- form render field baru (Kategori/Deskripsi/Berat/Dimensi/Barcode + galeri).
- akses non-izin ditolak (403).
- **HQ tak tersentuh** (aksi ini murni master + files).
- Foto pakai `Storage::fake('public')` + `UploadedFile::fake()->image()`.

## 8. Deploy & risiko

- Deploy: `git pull` + `migrate --force` (000150) + `optimize:clear`.
- Risiko kecil: field baru murni internal (tak ada API eksternal). Foto banyak = reuse infra HasFiles/ImageService/File yang sudah ada. Zero-dependency.
- **Ekspektasi user (harus jelas di UI/komunikasi):** field baru selain harga/stok belum nyambung ke marketplace.
