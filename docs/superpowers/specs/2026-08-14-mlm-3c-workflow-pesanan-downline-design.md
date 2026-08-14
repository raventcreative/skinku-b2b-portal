# Workflow "Pesanan Downline" — Desain

**Tanggal:** 2026-08-14
**Branch:** `feat/mlm-3c-workflow-pesanan-downline` (dari `main` @ a568b87 — setelah engine 3c + companion pelaporan)
**Konteks:** Engine 3c ("Model X") sudah merutekan PO downline ke upline-nya (`purchase_orders.seller_id`) dan `complete()` sudah memindah stok seller→buyer. Companion pelaporan sudah mengecualikan inter-partner dari buku HQ. **Yang belum ada:** upline (mitra) tak punya layar/izin untuk memproses pesanan dari downline-nya — sekarang hanya HQ (izin `update_po_status`) yang bisa. Workflow ini menambah *pintu sisi-penjual* itu.

## Tujuan
Upline bisa **self-service** memproses PO dari downline-nya sendiri: lihat pesanan masuk, verifikasi pembayaran, kirim/selesaikan (transfer stok), atau tolak (dengan alasan) — **ter-scope** ke pesanan di mana dia penjual (`seller_id = dia`). HQ tetap punya visibilitas & kendali penuh (tak berubah).

## Arsitektur
Zero-dependency, **tanpa migrasi** (`seller_id` sudah ada). **Reuse total service layer** (`PurchaseOrderService::verifyPayment/updateStatus/cancel` — sudah terbukti memindah stok untuk PO `seller_id`). Yang **baru**: satu permission, satu controller tipis (`DownlineOrderController`), grup route, dua view (daftar + detail trim), satu nav item. Pola meniru `JaringanSayaController` (controller+route+view berdiri sendiri untuk fitur mitra).

## Alur (pakai mesin yang sudah ada)
1. Downline bikin PO seperti biasa → `createForPartner` set `seller_id = upline_id` (sudah jalan).
2. Upline buka **"Pesanan Downline"** → daftar PO `where seller_id = dia`.
3. Downline transfer + upload bukti (fitur `uploadPayment` sudah ada) → `payment_status = awaiting_verification`.
4. Upline **verifikasi bayar** → `PurchaseOrderService::verifyPayment` set `paid` (atau `rejected`), stempel `payment_verified_by = upline`.
5. Upline **Kirim/Selesai** → `PurchaseOrderService::updateStatus($po, STATUS_COMPLETED)` → gate bayar (`isPaid`/`is_tempo`) → `complete()` → potong stok upline + tambah stok downline.
6. Upline **Tolak** (alasan) kapan saja sebelum selesai → `PurchaseOrderService::cancel($po, $reason)`.

## KEPUTUSAN OTORISASI (bagian paling kritis)
- **Permission BARU `process_downline_po`** di `Permissions::DEFINITIONS` + `DEFAULTS` (default = semua tier partner: distributor/grand/reseller/bronze/gold). Gate grup route baru dengan `permission:process_downline_po`.
- **JANGAN pakai/berikan `update_po_status`** ke mitra. Izin itu role-wide TANPA scoping kepemilikan (call site `updateStatus`/`verifyPayment` HQ tak punya cek inline) → memberi mitra = mitra bisa sentuh PO SIAPA SAJA (termasuk PO HQ & downline mitra lain). Bahaya.
- **Setiap aksi WAJIB ada guard kepemilikan inline** (allow-list, meniru pola `uploadPayment` / `show`):
  ```php
  abort_unless($po->seller_id === $user->id, 403, 'Ini bukan pesanan downline Anda.');
  ```
  Ini yang membatasi upline hanya ke pesanan downline-nya. JANGAN pakai pola exclusion-list `cancel()` (`if isPartner`) yang bocor untuk role non-partner.
- App ini **tak punya Policy/Gate** sama sekali — otorisasi = middleware + `if` inline. Ikuti pola itu (jangan kenalkan Policy baru).
- HQ (`super_admin`/staff) tetap lewat layar admin existing (`PurchaseOrderController`) — tak disentuh.

## Komponen baru
### 1. Permission
`app/Support/Permissions.php`: tambah key `process_downline_po` ke `DEFINITIONS` (label "Proses Pesanan Downline") + `DEFAULTS` (array tier partner). `super_admin` lolos otomatis.

### 2. `DownlineOrderController` (baru)
- `index(Request)`: daftar PO `where('seller_id', $user->id)`, eager `with('user')` (nama downline), filter status opsional, paginate. Gate route `permission:process_downline_po` (mitra pasti lolos; guard kepemilikan per-baris tak perlu di list karena query sudah `seller_id = me`).
- `show(PurchaseOrder $po)`: guard kepemilikan (`abort_unless seller_id===me`). Tampilkan detail + item + bukti bayar (`$po->paymentProofUrl()`) + **pre-cek stok** (lihat §5).
- `verifyPayment(Request, PurchaseOrder $po)`: guard kepemilikan → `PurchaseOrderService::verifyPayment($po, approve:bool, $note, verifierId: $user->id)`.
- `fulfill(Request, PurchaseOrder $po)`: guard kepemilikan → `PurchaseOrderService::updateStatus($po, STATUS_COMPLETED, $notes)` (gate bayar + `complete()` jalan). **Pre-cek stok** sebelum panggil (biar pesan rapi, bukan exception generik).
- `reject(Request, PurchaseOrder $po)`: guard kepemilikan + `validate reason` → `PurchaseOrderService::cancel($po, $reason)`.

### 3. Routes (baru, grup `permission:process_downline_po`)
`routes/web.php` (dekat blok PO), nama `pesanan-downline.*`:
`GET /pesanan-downline` (index), `GET /pesanan-downline/{purchaseOrder}` (show), `POST .../verify-payment`, `POST .../fulfill`, `POST .../reject`.

### 4. Views (baru — JANGAN reuse view PO staff)
- `resources/views/pesanan_downline/index.blade.php`: tabel (downline, produk ringkas, total, status, status bayar) + link ke detail. Pola `purchase_orders/index.blade.php` tapi trim.
- `resources/views/pesanan_downline/show.blade.php`: detail + item + bukti transfer + tombol aksi (Verifikasi bayar · Kirim/Selesai · Tolak). Tombol "Kirim/Selesai" **disabled + pesan** kalau belum lunas ATAU stok upline kurang. Fork trim dari `purchase_orders/show.blade.php` (buang shipping/tempo/delete/bulk).

### 5. Pre-cek stok (UX, cegah exception generik)
Sebelum fulfill, baca stok upline: `Inventory::where('user_id', $po->seller_id)->pluck('quantity','product_id')`, bandingkan tiap `$po->items` (`product_id`,`qty`). Kalau ada yang kurang → tombol Kirim disabled + daftar produk yang kurang (berapa tersedia vs butuh). (Engine `adjustPartnerStock` tetap jaring pengaman terakhir — lempar `RuntimeException` kalau kurang → `complete()` rollback; tapi pesannya generik tanpa nama produk, makanya pre-cek di depan.)

### 6. Nav
`resources/views/layouts/app.blade.php`: tambah item, pola sama "Jaringan Saya":
```blade
@if($u->isPartner() && $u->downlines()->exists())
    {!! navItem('pesanan-downline.index', 'Pesanan Downline', 'pesanan-downline.index') !!}
@endif
```

## AKUNTANSI — HQ-only (kunci, dari user)
Workflow ini **TIDAK menambah akuntansi/pembukuan apa pun untuk mitra**. Verifikasi bayar = **penanda status** (`payment_status`), BUKAN entri jurnal. Tak ada buku/GL per-mitra (belum fix). Inter-partner PO tetap **tak di-journal ke HQ** (sudah dijamin engine — `complete()` tak nulis `acc_`) dan sudah dikecualikan dari laporan omzet HQ (companion). HQ tetap satu-satunya yang punya akuntansi. Rekonsiliasi uang antar-mitra = manual/Excel.

## Status flow (reuse mesin existing)
Masuk = `pending`. Verifikasi bayar ubah `payment_status` (bukan status PO). Kirim/Selesai → `completed` (via `updateStatus`; gate bayar di service :135-138). Tolak → `cancelled`. **Verifikasi di plan:** apakah `pending → completed` langsung diizinkan `TRANSITIONS`, atau perlu `advanceStatus()` lewat status antara — reuse persis mekanisme yang dipakai HQ (`updateStatus`/`advanceStatus`), jangan bikin jalur baru.

## Dormant-safe
Nav muncul hanya untuk mitra yang punya downline. Jaringan kosong → tak ada yang lihat menu ini, nol dampak. Layar HQ existing tak berubah.

## Testing
- **Otorisasi (kritis):** upline lihat/proses pesanan downline-nya (200/berhasil); upline lain / mitra tanpa relasi coba akses PO itu → 403; mitra tanpa izin → 403 middleware. PO HQ (seller null) tak muncul di list mitra manapun.
- **Verifikasi bayar:** upline verifikasi → `payment_status = paid`, `payment_verified_by = upline`.
- **Fulfill:** upline (setelah lunas) Kirim → stok upline −qty, stok downline +qty, status completed (reuse engine — transfer benar).
- **Gate bayar:** fulfill sebelum lunas & bukan tempo → ditolak (pesan), stok tak berubah.
- **Stok kurang:** pre-cek menandai; kalau dipaksa, `complete()` rollback, PO tak completed.
- **Tolak:** upline tolak + alasan → `cancelled`, alasan tersimpan, stok tak tersentuh.
- **Regresi:** suite existing (750) tetap hijau; layar PO HQ tak berubah.

## Rencana build (subagent-driven)
1. **Izin + daftar** — `process_downline_po` + route grup + `DownlineOrderController::index` + nav + view index + tes (scope seller + 403).
2. **Detail + pre-cek stok** — `show` + guard kepemilikan + view detail + pre-cek stok + tes (lihat sendiri vs 403).
3. **Aksi** — `verifyPayment` + `fulfill` + `reject`, tiap-tiap guard kepemilikan, reuse service + tes (transfer stok, gate bayar, stok kurang, tolak, 403 lintas-mitra).

## Out of scope (fase lain)
- Payment gateway otomatis (Xendit dll) — nanti.
- Tracking pengiriman terpisah (dikirim vs diterima) — cukup satu aksi Kirim/Selesai.
- Buku akuntansi per-mitra — tetap Excel/hold.
- Notifikasi realtime.
- Refactor `PurchaseOrderController::show()` gate (biarkan; kita pakai controller terpisah).

## Self-review
- **Placeholder:** tak ada; tiap komponen punya file/method + pola acuan file:line.
- **Konsistensi:** otorisasi = permission baru + guard `seller_id===me` di SETIAP aksi (bukan `update_po_status`, bukan Policy). Reuse service (verifyPayment/updateStatus/cancel). View baru (bukan reuse staff).
- **Ambiguitas:** transisi `pending→completed` ditandai "verifikasi di plan" (reuse mekanisme HQ). Pembayaran = status flag, ditegaskan no-accounting.
- **Scope:** satu subsistem (pemrosesan pesanan sisi-upline) — pas untuk satu plan.
