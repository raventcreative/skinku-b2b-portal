# SKINKU — Laporan Income TikTok (rekonsiliasi via upload)
### Spec untuk repo `laravel-b2b` (system.skinku.id)

> Konteks buat Claude Code. Baca penuh sebelum ngoding.
> Tujuan: **migrasi report "Tiktok income" dari bot n8n → ke dalam SKINKU.**

---

## 1. LATAR & KEPUTUSAN

- **Masalah user:** laporan pencairan TikTok cuma nampilin **Order ID tanpa SKU** → susah tahu barang apa buat dipotong stok. Solusi manualnya: tarik **"Semua pesanan"** (Order ID + SKU) + **"income"** (settled per order), digabung by Order ID di bot n8n → file **"Tiktok income"**.
- **Fakta sistem:** mesin TikTok SKINKU sebenarnya **sudah lengkap** via API — peta SKU (`TiktokSkuMap`+`TikTokOrderService::resolve()`), potong stok, **funnel stok** (transit/terkirim/sisa/total), settlement, jurnal. TAPI (a) belum ada **halaman laporan** berformat "Tiktok income", dan (b) settlement API tersimpan **per-BATCH (statement)**, bukan per-order → income-per-order belum ada di data tersimpan.
- **Keputusan:** **Fase 1 = jalur UPLOAD** (andal, output persis, pakai file per-order yang user sudah percaya, tak gantung ke kestabilan Finance API). Fase 2 (otomatis dari API) menyusul.

---

## 2. RUANG LINGKUP FASE 1

- Halaman **"Laporan Income TikTok"** — sub-halaman di **Integrasi TikTok**, izin `manage_tiktok`.
- Upload 2 file → join by Order ID → **tabel di layar** + **unduh Excel** format "Tiktok income".
- **REPORT-ONLY: TIDAK menyentuh stok.** Pemotongan stok tetap lewat jalur API (hindari dobel-hitung).
- **Stateless:** proses saat upload, tampil + unduh. **Tanpa tabel/migrasi baru.**

---

## 3. SUMBER DATA (sudah diperiksa dari file asli)

**File 1 — "Semua pesanan" (.csv, 65 kolom, per-baris SKU):**
`[0] Order ID` · `[6] Seller SKU` · `[9] Quantity` · `[1] Order Status` · `[28] Order Amount` · `[29..33]` waktu (Created/Paid/RTS/Shipped/Delivered). Order ID & SKU ada `\t` nyangkut → `SpreadsheetReader` **otomatis trim**.

**File 2 — "income" (.xlsx, sheet-1 "Detail pesanan", per-order):**
`[0] ID Pesanan` · `[1] Jenis transaksi` · `[5] Jumlah penyelesaian pembayaran` (settlement) · `[6] Total Pendapatan` · `[14] Total Biaya` (fee). `SpreadsheetReader` baca **sheet pertama** = Detail pesanan.

**Kunci join = Order ID.** Iterasi order di file income (yang settled) → cocokkan ke Order ID di file pesanan → ambil SKU + qty.

---

## 4. ITEM-BESAR (kolom dinamis)

- Tiap **Seller SKU** → komponen produk lewat **`resolve()`** (peta `TiktokSkuMap` yang sudah ada; dukung bundle **"1 SKU = 3 sabun"** + bundle campur banyak komponen).
- **Kolom item-besar = kategori produk** (`Product.category`) dari komponen hasil resolve. Nilai kolom = Σ(`order_qty` × `komponen_qty`) per kategori.
- **Dinamis:** daftar kolom = kategori yang MUNCUL di data → **otomatis nggeser** kalau ada kategori/produk baru.

---

## 5. OUTPUT

- **Ringkasan validasi** (ala bot): baris CSV terbaca · order unik · order income · order ketemu/tak-ketemu di pesanan · **SKU belum dipetakan** (daftar).
- **Tabel per order settled:** Order ID · waktu · settlement · fee · revenue · **kolom item-besar (qty)**.
- **Unduh Excel** (`XlsxWriter`): urutan kolom = "Tiktok income" (ID · Type · waktu · Subtotal/Revenue/Fees/Settlement · **[item-besar dinamis]** · fee-fee), bagian item **nggeser otomatis**.
- **SKU belum dipetakan** → tautan ke halaman **Peta SKU** yang sudah ada (`tiktok.orders`), biar dilengkapi sekali.

---

## 6. PAKAI ULANG (zero-dependency, tanpa paket/migrasi baru)

Baca: `SpreadsheetReader` (csv+xlsx). Tulis: `XlsxWriter`. Peta & komposisi: `TiktokSkuMap` + `TikTokOrderService::resolve()`/`skusNeedingMap()`. Kategori: `Product::category`. Izin: `manage_tiktok`.

---

## 7. UJI (pakai fixture kecil, BUKAN file 11 MB)

Parse csv (trim `\t`) + xlsx sheet-1 · join by Order ID (matched & unmatched) · `resolve()` bundle → qty item-besar benar · SKU tak dikenal terdeteksi · Excel ter-generate dengan kolom dinamis · render halaman + gate izin `manage_tiktok`.

---

## 8. ROADMAP

- **Fase 1 (ini):** upload → laporan + Excel.
- **Fase 2 (nanti):** otomatis dari data API (butuh sync **settlement per-order** dari transaksi statement) atau hybrid (upload income buat sisi uang). Tetap pakai ulang mesin yang ada.

---

## 9. KEPUTUSAN TERBUKA (butuh jawaban Freddie)

1. **Item-besar = `Product.category`?** — master produk sudah bagi jadi "Sabun/Lotion/Scrub/…"? Kalau beda, kolom ikut kategori master apa adanya (atau tambah field "item besar" khusus).
2. **Sheet income** = sheet pertama (Detail pesanan) — asumsi aman; kalau TikTok ubah urutan sheet, nanti tambah pemilih sheet.
3. **Upload sekali** (2 file bareng, 1 form) vs **2-langkah** ala bot — rekomendasi **sekali**.
