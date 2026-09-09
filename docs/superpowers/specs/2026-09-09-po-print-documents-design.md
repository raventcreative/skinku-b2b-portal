# Cetak Dokumen PO (Label / Packing / Faktur) — Desain

**Tanggal:** 2026-09-09
**Repo:** `skinku-b2b-php`
**Status:** Disetujui (design) — siap dibuat rencana implementasi.

## Tujuan

Tombol **"Cetak Dokumen"** di Purchase Order → dialog pilih dokumen (mirip Seller
Center) → cetak lewat browser. Tahap 1: dokumen dari data PO (HQ → mitra) —
**Label Pengiriman** (buat ditempel di paket), **Daftar Pengemasan** (packing
list), dan **Faktur/Nota**. Struktur disiapkan supaya kelak tinggal "colok" API
kurir (J&T) tanpa ubah template.

## Ruang Lingkup

- **Fase 1 (spec ini):** cetak dokumen untuk **Purchase Order** (`purchase_orders`).
  Semua data dari PO + mitra + setelan pengirim HQ.
- **DI LUAR Fase 1 (nanti/terpisah):** label order channel (TikTok/Shopee) — resi/AWB
  itu milik marketplace + kurir, dicetak dari Seller Center; **integrasi kurir
  beneran** (JNE/J&T API booking + AWB otomatis); **cetak massal** (centang banyak
  PO sekaligus). Ketiganya spec sendiri.

## Fakta Kode yang Dipakai (hasil telaah)

- **PO belum punya kolom resi/kurir.** `purchase_orders` punya `shipping_address`,
  `shipping_cost`, `notes`, `po_number`, `total_amount`, `subtotal`, `discount`,
  `payment_status`, relasi `user` (mitra) & `items` (product_id, qty, harga).
  → tambah kolom `no_resi` + `kurir`.
- **Data pengirim (HQ) belum ada** di mana pun (SettingController cuma setelan AI).
  → tambah setelan Pengirim HQ (nama/alamat/kota/HP) di Setelan Sistem.
- **Produk punya `sku` tapi TIDAK punya berat.** → kolom "Berat" di label DI-SKIP
  Fase 1 (berat kurir dihitung kurir sendiri nanti).
- **Zero-dependency** (tak boleh tambah paket composer/npm). → cetak = **halaman
  Blade khusus print** + **browser Print** (Ctrl+P / `window.print()`), BUKAN
  generate PDF di server. Barcode digambar **SVG murni** (tak ada lib barcode).
- **Runner tes:** `/c/php83/php.exe artisan test`. Pint sebelum commit. Migrasi
  terakhir `000124` → migrasi baru `000125`.

## Arsitektur

Halaman cetak berdiri sendiri (tanpa layout app) yang me-render dokumen terpilih,
lalu memanggil `window.print()`. Ukuran diatur CSS `@page`. Dialog di detail PO
mengumpulkan pilihan dokumen + ukuran, lalu membuka URL cetak di tab baru.

### 1. Data model

**Migrasi `000125_add_shipping_fields_to_purchase_orders`** — kolom di
`purchase_orders`:
- `kurir` string(50) nullable — nama kurir (mis. "J&T Express").
- `no_resi` string(64) nullable — nomor resi/AWB (input manual sekarang; diisi API
  kurir nanti).

Tambah keduanya ke `PurchaseOrder::$fillable`.

**Setelan Pengirim HQ** — pakai `AppSetting` (key/value), key:
`hq_sender_name`, `hq_sender_address`, `hq_sender_city`, `hq_sender_phone`.
Diedit di Setelan Sistem (`SettingController`), gate `system_settings`.

### 2. Input resi/kurir (staf)

Di halaman **Detail PO** (`purchase_orders/show`), form kecil (gate staf —
`update_po_status`) untuk isi/ubah `kurir` + `no_resi`. Route
`POST /purchase-orders/{purchaseOrder}/resi` → `PurchaseOrderController::saveResi`
(validasi nullable string; simpan; audit `update_po_resi`).

### 3. Helper barcode (zero-dep)

`app/Support/Barcode.php` — `Barcode::code128(string $value): string` mengembalikan
markup **SVG** (rangkaian `<rect>` batang hitam) hasil encode Code128-B. Murni PHP,
tanpa paket. Dipakai di label (nilai = `no_resi` bila ada, else `po_number`).

### 4. Tombol & dialog "Cetak Dokumen"

Di **Detail PO** (gate staf): tombol **"Cetak Dokumen"** → buka `<dialog>` native
(zero-dep) berisi:
- Checkbox: **Label Pengiriman**, **Daftar Pengemasan**, **Faktur/Nota** (default
  Label + Packing tercentang).
- Dropdown **Ukuran**: A6 (default) / A4.
- Tombol **Cetak** → buka
  `GET /purchase-orders/{po}/cetak?docs=label,packing,faktur&size=A6` di tab baru.

### 5. Halaman cetak

Route `GET /purchase-orders/{purchaseOrder}/cetak` →
`PurchaseOrderController::print` (gate staf; mitra tak boleh cetak label HQ).
Validasi `docs` (subset dari `label,packing,faktur`) & `size` (A6|A4; default A6).
Render view **`purchase_orders/print.blade.php`** — HTML BERDIRI SENDIRI (punya
`<head>` + CSS print sendiri, TIDAK extend `layouts.app`):
- `@page { size: {size}; margin: 4mm; }` + `@media print` rapikan.
- Tiap dokumen terpilih = satu blok `.doc-page` dengan `page-break-after: always`.
- Script kecil: `window.onload = () => window.print()` (auto buka dialog print).
  (Ukuran/scale tetap bisa diubah user di dialog print browser.)

**Isi dokumen (semua dari `$purchaseOrder`):**

- **Label Pengiriman (A6):**
  - **Pengirim:** setelan HQ (nama · alamat, kota · HP).
  - **Penerima:** mitra — `user->fullname` · `shipping_address` (fallback
    `user->address`, kota) · `user->phone`.
  - **Kurir:** `kurir` (bila ada).
  - **Barcode:** `Barcode::code128($no_resi ?: $po_number)` + teks nilainya.
  - Ringkas: "Jumlah: {total qty} pcs" · **No PO** · tanggal (`orderDate()`).
- **Daftar Pengemasan / Packing list:**
  - Header: No PO · mitra · tanggal.
  - Tabel: **Produk | SKU | Qty** (+ total qty).
- **Faktur/Nota (A4):**
  - Header: pengirim HQ + judul "FAKTUR / NOTA" · No PO · tanggal · mitra.
  - Tabel: **Produk | Qty | Harga satuan | Subtotal**.
  - Subtotal · diskon · ongkir (`shipping_cost`) · **Total** (`total_amount`).
  - Status bayar: Lunas / Tempo (sisa).

### 6. Siap "colok API J&T" nanti

Label & alur baca `kurir`/`no_resi` dari kolom PO. Sekarang diisi manual; saat
integrasi J&T, service kurir cukup mengisi kolom itu (dan mungkin AWB/QR) — dialog,
route, dan template TIDAK berubah.

## Alur Data

1. Staf buka Detail PO → isi kurir + no_resi (opsional) → simpan.
2. Klik "Cetak Dokumen" → dialog pilih dokumen + ukuran → Cetak.
3. Tab baru buka `/purchase-orders/{po}/cetak?docs=…&size=…` → render dokumen →
   `window.print()` → user pilih printer/scale/ukuran → cetak.

## Penanganan Error / Kasus Batas

- `docs` kosong/ngawur → default ke `label`. `size` selain A6/A4 → A6.
- `no_resi` kosong → barcode & tampilan pakai `po_number`.
- Setelan pengirim kosong → blok Pengirim tampil "—" (tak error).
- Mitra coba akses route cetak → 403 (staf-only), sama seperti aturan lihat PO.
- Ongkir belum diisi → tampil Rp 0 di faktur (bukan error).

## Rencana Tes

- **Unit `Barcode::code128`:** balikin string diawali `<svg`, deterministik untuk
  input sama, beda untuk input beda (bukan kosong).
- **Feature cetak:** staf GET `…/cetak?docs=label,faktur&size=A6` → 200, memuat
  nama pengirim HQ (dari setelan), `fullname` mitra, `po_number`, dan (untuk faktur)
  total; mitra → 403; `docs` ngawur → tetap 200 (fallback label).
- **Feature simpan resi:** staf POST resi/kurir → tersimpan di PO; non-staf → 403.
- **Feature setelan pengirim:** simpan 4 key HQ → kebaca di label.
- **Feature detail PO:** tombol "Cetak Dokumen" + form resi tampil untuk staf,
  tidak untuk mitra.

## Migrasi & Deploy

- Migrasi baru `000125_add_shipping_fields_to_purchase_orders` (kolom `kurir`,
  `no_resi`).
- Deploy: `git pull && php artisan migrate --force && php artisan optimize:clear`.
- Setelah deploy: isi **Setelan Pengirim HQ** di Setelan Sistem (nama/alamat/kota/HP)
  supaya blok Pengirim di label benar.
