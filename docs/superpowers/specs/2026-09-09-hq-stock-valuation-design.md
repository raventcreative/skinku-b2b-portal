# Nilai Persediaan di Laporan Stok HQ — Desain

**Tanggal:** 2026-09-09
**Repo:** `skinku-b2b-php`
**Status:** Disetujui — implementasi inline TDD (fitur kecil, tanpa migrasi/route baru).

## Tujuan

Di Laporan Mutasi Stok HQ, tambah kolom **nilai persediaan** supaya bisa cek nominal
modal barang tersimpan: harga per unit × Stok Akhir, plus grand total.

## Keputusan (disetujui user)

- **Dua kolom nilai:** Nilai HPP (modal) dan Nilai Jual (potensi omzet), berdampingan,
  dengan dua grand total.
- **Basis harga per unit:**
  - HPP = `products.cogs` (harga pokok).
  - Jual = `products.price_retail` (harga eceran/SRP).
- **Basis stok:** **Stok Akhir** periode terpilih (bukan stok awal).
- **Gate tetap `manage_hq_stock`** (admin/gudang) — angka HPP tak bocor ke mitra.

## Ruang Lingkup

- **Termasuk:** kolom di halaman web + di **Export Excel**; grand total keduanya.
- **Di luar:** valuasi FIFO/rata-rata bergerak, nilai per-periode-historis (pakai
  `cogs` terkini apa adanya), penilaian stok mitra. Semua di luar Tahap ini.

## Fakta Kode

- `Product` punya `cogs`, `price_retail` (dan tier lain), semua `required` di Produk
  Master → selalu terisi.
- `HqStockReportService::report()` mengembalikan `rows` (tiap row: `product`, `awal`,
  `akhir`, bucket) + `totals`. Web (`inventory/hq_report`) dan Export (`ExportController::stokHq`)
  sama-sama pakai output ini → hitung nilai di service supaya konsisten.

## Perubahan

### 1. `app/Services/HqStockReportService.php`
- Per row tambah: `nilai_hpp = akhir × (float) cogs`, `nilai_jual = akhir × (float) price_retail`.
- `totals` tambah `nilai_hpp` + `nilai_jual` (akumulasi tiap row).
- Baris "empty" (akhir 0) menyumbang 0 → total web (includeEmpty) = total export (tanpa empty).

### 2. `resources/views/inventory/hq_report.blade.php`
- Grup header baru (colspan 4) "Nilai Persediaan (Stok Akhir)" setelah Stok Akhir,
  sub-kolom: **HPP/Unit**, **Nilai HPP**, **Jual/Unit**, **Nilai Jual**.
- Body: 4 sel per baris (HPP/unit & Jual/unit dari `product`, Nilai dari row).
- Footer TOTAL: HPP/Unit & Jual/Unit = "—" (per-unit tak punya total), Nilai HPP &
  Nilai Jual = grand total.
- Angka Rupiah `number_format($v,0,',','.')`; catatan kaki: nilai dalam Rupiah, HPP=cogs,
  Jual=harga retail.

### 3. `app/Http/Controllers/ExportController.php` (`stokHq`)
- Tambah 4 kolom header + nilai per baris + baris TOTAL (Nilai HPP & Nilai Jual;
  HPP/Unit & Jual/Unit total dikosongkan).

## Tes

- `HqStockReportService`: 2 produk (cogs & retail beda, hq_stock beda, tanpa gerakan →
  akhir = hq_stock) → assert `nilai_hpp`/`nilai_jual` tiap row = akhir×harga, dan
  `totals.nilai_hpp`/`nilai_jual` = jumlahnya.

## Deploy

Tanpa migrasi. Deploy = `git pull && php artisan optimize:clear` (perubahan view).
