# Companion Pelaporan Model X — Desain

**Tanggal:** 2026-08-14
**Branch:** `feat/mlm-3c-companion-pelaporan` (dari `main` @ f7cc93f — setelah engine 3c Model X merged)
**Konteks:** Engine 3c "Model X" menambah `purchase_orders.seller_id` (null = HQ; terisi = upline yang jual ke downline). Engine sudah live-di-main tapi **dorman** (jaringan kosong). Companion ini menyiapkan lapisan pelaporan **sebelum** jaringan diisi.

## Tujuan
Dua sisi dari fondasi `seller_id` yang sama:
- **Bagian A — Laporan HQ = murni HQ.** Setiap metrik finansial/operasional HQ mengecualikan PO antar-mitra (`seller_id != null`), supaya buku HQ tidak menggelembung oleh penjualan mitra→downline.
- **Bagian B — Laporan "Omzet Mitra" (buat HQ).** Halaman baru: HQ memantau **total jualan tiap mitra** = jual ke downline (PO di mana mitra jadi `seller_id`) + jual ke customer akhir (`PartnerSale`).

## Arsitektur
Zero-dependency (Blade + Eloquent + vanilla). **Tanpa migrasi** — `seller_id` & `PartnerSale` sudah ada. Perubahan = tambah filter query di titik-titik pelaporan HQ (Bagian A) + satu method agregasi baru + satu halaman laporan (Bagian B). Aktivitas antar-mitra tetap tercatat utuh; hanya di-*slice* berbeda per konteks.

## Prinsip pemandu
- **HQ context** (viewer = staff/HQ, atau query company-wide) → hanya `seller_id IS NULL`.
- **Partner context** (viewer = mitra melihat datanya sendiri) → **JANGAN diubah**. Pembelian mitra dari upline-nya (`seller_id != null`, dia sebagai pembeli) itu sah bagian datanya sendiri.
- **Operasional "daftar semua PO"** (manajemen PO, export PO, widget PO terbaru) → **JANGAN diubah** (HQ staff tetap butuh lihat/proses semua PO; ini di luar ReportService).

## Global Constraints
- Zero-dependency: tak nambah paket composer/npm.
- Tanpa migrasi baru.
- Runner: `C:\php83\php.exe artisan test`. Pint `--dirty` sebelum tiap commit.
- Semua perubahan filter WAJIB punya tes yang membuktikan (a) HQ mengecualikan inter-partner, (b) view mitra sendiri TIDAK berubah (regresi).
- Suite existing (740) harus tetap hijau.

---

## Bagian A — Pengecualian di laporan HQ

### A1. `ReportService::scopePo()` jadi kondisional (menutup 3 titik sekaligus)
`app/Services/ReportService.php:45-52`. Saat ini hanya menyaring `user_id` untuk viewer mitra. Ubah supaya cabang **HQ** (viewer null / bukan mitra) menambah `whereNull('seller_id')`:

```php
private function scopePo($query, ?User $viewer)
{
    if ($viewer && $viewer->isPartner()) {
        $query->where('user_id', $viewer->id);   // view mitra sendiri — TAK diubah
    } else {
        $query->whereNull('seller_id');           // view HQ — hanya penjualan HQ
    }
    return $query;
}
```
Menutup: `summary()` (total_sales :68/:76 + jumlah PO :80-82), `salesTrend()` (:218-222), `poStatusDistribution()` (:365-368). Untuk viewer mitra ketiganya tetap seperti sekarang (aman).

### A2. Enam titik per-call-site (tak lewat scopePo)
Tambah `seller_id IS NULL` langsung. Untuk query Eloquent: `->whereNull('seller_id')`. Untuk raw `DB::table` join-item: `->whereNull('po.seller_id')` (kolom di-qualify).

| Method | Baris | Cara |
|--------|-------|------|
| `channelSales()` | :117-131 | Eloquent — `whereNull('seller_id')` di closure `$po` |
| `grossProfit()` | :187-194 | raw join — `whereNull('po.seller_id')` (sudah ada guard `po.deleted_at`) |
| `salesByProduct()` | :279-288 | raw join, punya logika viewer inline (:283-285) — hanya tambah `whereNull('po.seller_id')` untuk cabang **non-mitra** (mirror A1) |
| `partnerSalesDetail()` | :312-315 | Eloquent — `whereNull('seller_id')` |
| `salesByPartner()` | :331-334 | Eloquent — `whereNull('seller_id')` |
| `salesByRegion()` | :349-352 | Eloquent (join users) — `whereNull('purchase_orders.seller_id')` (qualify) |

**Catatan makna (bukan bug):** `salesByPartner` & `partnerSalesDetail` GROUP BY **pembeli**. Dengan filter, artinya bergeser jadi "berapa HQ jual ke tiap mitra" (bukan "total belanja mitra dari mana pun"). Ini justru makna yang benar untuk laporan HQ. Didokumentasikan biar sadar.
**Di luar cakupan:** `salesByProduct` kurang guard `deleted_at` (celah lama, tak berhubungan) — JANGAN diperbaiki di sini, jangan diperburuk.

### A3. `OkrBusinessSnapshotService`
`app/Services/OkrBusinessSnapshotService.php`:
- **Omzet distributor** :446-452 (SUM group by buyer) → `whereNull('seller_id')` (= omzet dari HQ per distributor).
- **Hitungan status PO (coo)** :293-297 → `whereNull('seller_id')` (pandangan operasional PO HQ).
- **Piutang tempo HQ** :201-213 → `whereNull('seller_id')` (piutang ke HQ; utang antar-mitra bukan piutang HQ).

### A4. KEPUTUSAN — funnel engagement TETAP inklusif (carve-out)
`OkrBusinessSnapshotService` funnel **pernah-PO** (:461-464) & **aktif-30-hari** (:468-473) menghitung `COUNT(DISTINCT user_id)` = **apakah mitra aktif**, bukan uang. Mitra yang aktif beli dari upline-nya **tetap aktif** — mengecualikan inter-partner di sini akan menyembunyikan mitra yang benar-benar aktif dan salah lapor engagement. **Keputusan: dua funnel ini TIDAK difilter.** (Beda dari "jumlah PO" di dashboard yang memang metrik PO-HQ.) Ini penyimpangan sadar dari "kecualikan semua" demi menjaga makna metrik; kalau HQ mau tetap difilter, tinggal tambah satu filter — tapi default kami inklusif.

### A5. JANGAN disentuh (pengaman)
- `RingkasDashboardTool.php:67-72` — piutang **milik mitra sendiri** (di dalam `if isPartner`). Bukan HQ. Jangan filter.
- `PurchaseOrderController` daftar PO, `ExportController::purchaseOrders`, widget PO terbaru `DashboardController` — operasional, di luar ReportService. Jangan diubah.
- Cabang mitra di `scopePo` — jangan tambah filter seller_id.

### A6. Tes Bagian A
File tes baru `tests/Feature/HqReportSellerExclusionTest.php` (ReportService belum punya tes):
- Buat 1 PO HQ (seller null) + 1 PO inter-partner (seller = upline) di bulan sama, keduanya completed.
- **HQ**: `summary()`/`salesTrend()`/`channelSales()`/`grossProfit()`/`salesByPartner()`/`salesByRegion()` (viewer null/staff) = hanya nilai PO HQ; inter-partner TIDAK terhitung.
- **Regresi mitra**: `summary($mitraPembeliInterPartner)` (viewer = mitra yang beli dari upline) TETAP memasukkan pembeliannya (angka tak turun) — buktikan cabang mitra utuh.
- OKR: `distributorSnapshot` omzet & piutang tempo mengecualikan inter-partner; funnel pernah-PO/aktif-30-hari TETAP menghitung mitra inter-partner (buktikan carve-out A4).

---

## Bagian B — Laporan "Omzet Mitra" (HQ)

### B1. Sumber data (tak ada dobel-hitung)
- **Jual ke downline** = `PurchaseOrder` `status = COMPLETED` `whereNotNull('seller_id')`, SUM `total_amount` GROUP BY `seller_id`. (Eloquent → soft-delete otomatis; kalau raw, tambah `whereNull('deleted_at')`.)
- **Jual ke customer** = `PartnerSale` SUM `total_amount` GROUP BY `user_id`. (`PartnerSale` secara struktur hanya mitra→customer akhir; tak pernah menyimpan transaksi antar-mitra → tak ada tumpang-tindih dengan PO.)
- Gabung per `user_id` mitra di PHP.

### B2. Method baru `ReportService::omzetPerMitra(?Carbon $month = null): array`
Ikuti konvensi `inMonth()`/`?Carbon $month` yang ada. Kembalikan array baris:
```
[ ['user_id'=>int, 'nama'=>string, 'tier'=>string(role label),
   'jual_downline'=>float, 'jual_customer'=>float, 'total'=>float], ... ]
```
- Ambil dua agregasi (PO by seller_id, PartnerSale by user_id), gabung by id.
- Sertakan hanya mitra yang punya total > 0 pada periode (biar tabel bermakna); urut `total` desc.
- Ambil nama/role dari `users`. `tier` = label role (pakai helper/label yang ada).
- Kalau `$month` null → semua periode (ikut pola `ALL_PERIODS`).

### B3. Halaman & rute (ikut pola `reports.index`)
- **Route:** `reports.omzet-mitra` di grup `permission:view_reports` (`routes/web.php:188-192`).
- **Controller:** method `omzetMitra(Request)` di `ReportController` (atau controller tipis baru mengikuti pola) — resolve user, `parseMonth()`, panggil `omzetPerMitra()`, return `view('reports.omzet_mitra', $data)`. **Gate staff-only** (`abort_unless($user->isStaff(), 403)`) — ini pandangan HQ.
- **View:** `resources/views/reports/omzet_mitra.blade.php` — `@extends('layouts.app')`, filter `<input type="month" name="bulan" onchange="this.form.submit()">` (pola `reports/index.blade.php:15-18`), tabel kolom: Mitra · Tier · Jual ke Downline · Jual ke Customer · **Total** (+ baris total keseluruhan). Format rupiah pakai helper existing. **Tanpa AJAX** → tak kena gotcha `shouldRenderJsonWhen(api/*)`.
- **Nav:** tambah `navItem('reports.omzet-mitra', 'Omzet Mitra', 'reports.omzet-mitra')` di `layouts/app.blade.php`, dibungkus `@if($u->isStaff())` (mirror pola item kondisional yang ada).

### B4. Tes Bagian B
`tests/Feature/OmzetMitraReportTest.php`:
- Mitra A: 1 PO inter-partner sebagai seller (mis. 100rb) + 1 PartnerSale (mis. 40rb) → `omzetPerMitra()` menghasilkan jual_downline=100rb, jual_customer=40rb, total=140rb.
- Mitra B: hanya PartnerSale → muncul dengan jual_downline=0.
- Mitra tanpa jualan → tidak muncul.
- PO seller-null (HQ→mitra) TIDAK dihitung sebagai jualan mitra manapun.
- Filter bulan: transaksi di luar bulan terpilih dikecualikan.
- Render: GET `reports.omzet-mitra` sebagai staff → 200 + tampil nama mitra & angka total; sebagai mitra → 403.

---

## Rencana build (subagent-driven, urutan)
1. **Bagian A / ReportService** (A1+A2) + tes A6 (bagian ReportService).
2. **Bagian A / OKR** (A3) + carve-out A4 + tes A6 (bagian OKR).
3. **Bagian B / service** — `omzetPerMitra()` + tes B4 (service).
4. **Bagian B / UI** — route+controller+view+nav + tes B4 (render/403).

Tiap task: TDD, Pint, commit, review. Akhir: review whole-branch (opus) → finishing-a-development-branch.

## Out of scope (fase lain)
- Workflow "Pesanan Downline" (upline proses PO) — plan berikutnya.
- Pembayaran antar-mitra.
- Fix celah `deleted_at` di `salesByProduct` (celah lama tak berhubungan).
- Buku akuntansi per-mitra (tetap akuntansi-hold / Excel).

## Self-review
- **Placeholder:** tak ada; tiap titik punya file:line + cara.
- **Konsistensi:** `seller_id` (nullable) dipakai konsisten; filter HQ = `IS NULL`, agregasi mitra = `IS NOT NULL`. Cabang mitra `scopePo` sengaja tak disentuh (regresi ditest).
- **Ambiguitas "kecualikan semua":** diselesaikan eksplisit — finansial+status-PO+piutang difilter; funnel engagement (pernah-PO/aktif-30-hari) sengaja inklusif (A4) dgn alasan makna metrik.
- **Cakupan:** satu subsistem (pelaporan) — pas untuk satu plan.
