# Kalkulator ROI — HPP → Profit → Target ROAS (TikTok Shop)

**Tanggal:** 2026-09-24
**Status:** Design (menunggu review user sebelum writing-plans)
**Modul:** Tool baru di SKINKU B2B (Laravel 13 / PHP 8.3). Sumber: Excel "Kalkulator ROI Semua +Pajak - PROFESIONAL - Skinku.xlsx".

---

## 1. Ringkasan
Porting kalkulator Excel ke fitur native: per produk, dari **Harga Jual + Modal**, hitung semua **potongan platform TikTok**, **Profit Bersih**, dan **Target ROI/ROAS iklan** (dengan & tanpa affiliate) + rata-rata sebagai patokan setelan iklan. Tabel terintegrasi katalog SKINKU, tersimpan, dengan setelan % global yang bisa di-override per produk.

## 2. Tujuan & Non-Tujuan
**Tujuan:**
- Tabel produk (tarik dari katalog SKINKU): Modal default dari **COGS** tapi **bisa di-edit**; Harga Jual diisi manual; hitung semua kolom Excel + rata-rata.
- Setelan biaya **global** (semua %/cap/rupiah bisa diubah) + **override per produk** (beda kategori TikTok bisa beda %).
- **Tersimpan** (daftar produk + input awet).
- Menu **sendiri**: "Kalkulator ROI".

**Non-Tujuan:**
- Bukan multi-channel (fokus struktur biaya TikTok Shop; Shopee/dsb bisa fase lanjut lewat setelan %).
- Tak menyentuh stok/HQ/marketplace-master (murni kalkulator angka).
- Tak auto-tarik Harga Jual dari marketplace (diisi manual; integrasi ke master e-commerce = opsi lanjut).

## 3. Keputusan terkunci (user)
- Bentuk = **tabel + tarik katalog SKINKU**; Modal default COGS tapi **editable** per baris.
- Setelan % = **global default + override per produk**; **cap & % komisi dinamis bisa disetting**; affiliate % bisa disetting.
- **Tersimpan**. Menu **berdiri sendiri "Kalkulator ROI"**.
- Total Biaya = **`SUM(G:M)`** (semua komponen biaya, termasuk Biaya Proses Order).

## 4. Model data

### 4.1 `roi_settings` (global default — 1 baris, id=1)
Semua **persen** disimpan sebagai angka persen (mis. `8` untuk 8%); rupiah sebagai integer.
| Kolom | Tipe | Default |
|---|---|---|
| id | bigint PK | 1 |
| admin_pct | decimal(6,3) | 8 |
| voucher_pct | decimal(6,3) | 4.5 |
| komisi_pct | decimal(6,3) | 5.5 |
| komisi_cap | integer | 650000 |
| mall_pct | decimal(6,3) | 1.8 |
| pajak_pct | decimal(6,3) | 0.5 |
| operasional_pct | decimal(6,3) | 3 |
| affiliate_pct | decimal(6,3) | 5 |
| packing_default | integer | 1000 |
| proses_order_default | integer | 1250 |
| timestamps | | |

### 4.2 `roi_items` (baris produk tersimpan)
| Kolom | Tipe | Ket. |
|---|---|---|
| id | bigint PK | |
| product_id | FK products.id | produk dari katalog |
| selling_price | integer | Harga Jual marketplace (diisi) |
| modal | integer, nullable | Modal; null = pakai `products.cogs` saat hitung; diisi = override COGS |
| packing | integer, nullable | null = pakai `roi_settings.packing_default` |
| proses_order | integer, nullable | null = pakai `roi_settings.proses_order_default` |
| admin_pct, voucher_pct, komisi_pct, komisi_cap, mall_pct, pajak_pct, operasional_pct, affiliate_pct | masing2 nullable | override per produk; null = ikut global |
| timestamps | | |

Unik `(product_id)` (1 baris kalkulator per produk). Nama produk diambil dari relasi `product`.

## 5. Perhitungan (service murni, tanpa tulis DB)

Nilai efektif tiap field = override baris bila non-null, else global (`roi_settings`); `modal` efektif = `modal ?? product.cogs`.

Untuk `price = selling_price`:
- `profit` = price − modal
- `admin` = admin_pct% × price
- `voucher` = voucher_pct% × price
- `komisi` = **min(komisi_pct% × price, komisi_cap)** ← cap diterapkan
- `mall` = mall_pct% × price
- `pajak` = pajak_pct% × price
- `operasional` = operasional_pct% × price
- `total_biaya` = admin + voucher + komisi + proses_order + mall + pajak + operasional  *(setara SUM(G:M))*
- `profit_bersih` = profit − packing − total_biaya
- `affiliate` = affiliate_pct% × price
- `profit_after_aff` = profit_bersih − affiliate
- `bep_roi` = price ÷ profit_bersih
- `target_roi_noaff[x]` = price ÷ (profit_bersih − x×price), untuk x ∈ {5%,10%,15%,20%}
- `bep_roi_aff` = price ÷ profit_after_aff
- `target_roi_aff[x]` = price ÷ (profit_after_aff − x×price)
- `avg_min` = (target_roi_noaff[10%] + target_roi_aff[10%]) ÷ 2
- `avg_optimum` = (target_roi_noaff[20%] + target_roi_aff[20%]) ÷ 2

**Pengaman bagi-nol/negatif:** kalau penyebut (`profit_bersih`, `profit_after_aff`, atau `… − x×price`) ≤ 0 → ROI-nya `null` (tampil "—", artinya rugi / tak tercapai). Tak bikin error/Infinity.

**Footer tabel:** `avg_min_all` = AVERAGE(avg_min semua baris valid), `avg_optimum_all` = AVERAGE(avg_optimum). (Baris ber-ROI null dilewati dari rata-rata.)

## 6. UI — halaman "Kalkulator ROI"

**Panel Setelan Biaya (global, collapsible):** input semua field `roi_settings` (%+cap+packing+proses order), tombol Simpan. Ada keterangan "default; bisa di-override per produk".

**Tabel produk:**
- Tombol **Tambah Produk**: native `<select>` katalog (produk yg belum ada di tabel) → buat baris (modal ke-isi COGS default, harga jual kosong).
- Kolom input per baris (inline, form POST + @csrf): Harga Jual, Modal (default COGS, editable), Packing, Proses Order. + tombol/expand **"Override %"** per baris (admin/voucher/komisi+cap/mall/pajak/operasional/affiliate; kosong = ikut global).
- Kolom hasil per baris: Total Biaya, Profit Bersih, (opsi Detail expand: rincian admin/voucher/komisi/mall/pajak/operasional + BEP & semua target), **BEP ROI**, **Target Min (10%)**, **Target Optimum (20%)** (tanpa/dgn aff dirata-rata). Hasil ROI null → "—".
- Hapus baris.
- **Footer**: Rata-rata Target ROI Min & Optimum semua produk (patokan setelan iklan).
- Zero-dep, native input, tanpa `@json([...])` literal, semua aksi form POST + `@csrf`.

## 7. Rute & izin
Izin baru `manage_roi_calculator` (pola `manage_*` di `app/Support/Permissions.php`; default `[User::ROLE_ADMIN]` + super_admin). Grup rute `permission:manage_roi_calculator`:
- `GET /kalkulator-roi` → index (tabel + setelan)
- `POST /kalkulator-roi/settings` → simpan setelan global
- `POST /kalkulator-roi/items` → tambah produk
- `POST /kalkulator-roi/items/{item}` → update baris (harga/modal/packing/proses/override %)
- `DELETE /kalkulator-roi/items/{item}` → hapus baris
Menu sidebar **berdiri sendiri** "Kalkulator ROI" (gate `$u->canDo('manage_roi_calculator')`, pola `canDo` — bukan `@can`).

## 8. Zero-dep & testing
- Tanpa composer package. **Tes PHPUnit class-style (no Pest); Product dibuat langsung.**
- Unit (`RoiCalculatorService`): profit/fees; **komisi cap diterapkan** (di bawah & di atas cap); total_biaya = SUM semua komponen; profit_bersih; BEP & target (tanpa/dgn aff); avg_min/optimum; **pengaman bagi-nol** (profit_bersih ≤ 0 → null); override per-produk menang atas global; modal null → pakai cogs. Cek angka cocok dgn contoh Excel baris 7 (Sabun): **input** Harga 39000, Modal 13755, **Packing 2000**, Proses Order 1250, %-default (Admin 8 / Voucher 4.5 / Komisi 5.5 cap 650000 / Mall 1.8 / Pajak 0.5 / Operasional 3 / Affiliate 5) → **hasil** Profit 25245, Admin 3120, Voucher 1755, Komisi 2145, Mall 702, Pajak 195, Operasional 1170, Total Biaya 10337, Profit Bersih 12908, BEP ≈ 3.02, dst. (Komisi cap: uji kedua produk mahal, mis. Harga 20.000.000 → komisi = 650000 bukan 1.100.000.)
- Feature: render halaman + akses (`manage_roi_calculator` vs ditolak); tambah/update/hapus baris; simpan setelan; footer rata-rata; modal default COGS + bisa diedit.

## 9. Out of scope
Multi-channel (Shopee), tarik Harga Jual otomatis dari marketplace, export/print, histori perubahan, grafik.

## 10. Deploy & risiko
- Deploy: `git pull` + `migrate --force` (2 tabel baru + izin) + `optimize:clear`.
- Angka acuan dari Excel dipakai sebagai test-fixture biar hasil identik.
- Cap komisi & semua % configurable → adaptif kalau TikTok ubah tarif.
