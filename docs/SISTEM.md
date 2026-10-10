# Dokumentasi Sistem SKINKU B2B — A sampai Z

> Dokumen acuan menyeluruh: apa saja modul yang ada, alur kerjanya, tabel/model, service inti, izin, dan cron. Ditulis dari pembacaan kode aktual (bukan asumsi). Terakhir dirangkai: **2026-08-26**, pada commit `56d2a6a` (`main`).

Kalau kamu baru pertama baca: mulai dari [Ringkasan](#0-ringkasan) → [Konvensi Arsitektur](#1-konvensi-arsitektur) → lalu lompat ke modul yang kamu butuh lewat daftar isi.

---

## Daftar Isi

**Fondasi**
- [0. Ringkasan](#0-ringkasan)
- [1. Konvensi Arsitektur](#1-konvensi-arsitektur)
- [2. Auth, User, Role & Izin](#2-auth-user-role--izin)

**Inti Bisnis (B2B / MLM)**
- [3. Hirarki MLM (2 pohon)](#3-hirarki-mlm-2-pohon)
- [4. Komisi, Withdraw, Onboarding](#4-komisi-withdraw-onboarding)
- [5. Purchase Order (PO)](#5-purchase-order-po)
- [6. Produk & Harga](#6-produk--harga)
- [7. Inventory / Stok](#7-inventory--stok)

**Marketplace & Akuntansi**
- [8. Integrasi TikTok](#8-integrasi-tiktok)
- [9. Integrasi Shopee (Fase 1–4)](#9-integrasi-shopee-fase-14)
- [9b. Kalkulator ROI](#9b-kalkulator-roi)
- [9c. Portal Content Creator](#9c-portal-content-creator)
- [9d. Produk Master E-commerce](#9d-produk-master-e-commerce)
- [10. Akuntansi / GL (buku besar)](#10-akuntansi--gl-buku-besar)

**Laporan & Produktivitas**
- [11. Dashboard & Laporan](#11-dashboard--laporan)
- [12. OKR (AI-drafted)](#12-okr-ai-drafted)
- [13. Kanban](#13-kanban)
- [14. Mindmaps](#14-mindmaps)
- [15. KOL (endorsement)](#15-kol-endorsement)
- [16. Report Bot (Telegram)](#16-report-bot-telegram)
- [17. AI Assistant (embedded)](#17-ai-assistant-embedded)
- [17b. Rekomendasi AI (Discovery)](#17b-rekomendasi-ai-discovery)
- [18. SKINKU Academy (LMS)](#18-skinku-academy-lms)
- [19. Material, Produksi, Supplier](#19-material-produksi-supplier)
- [19b. HR — Karyawan, Rekrutmen, Payroll](#19b-hr--karyawan-rekrutmen-payroll)

**Operasional**
- [20. Cron / Terjadwal](#20-cron--terjadwal)
- [21. Referensi Izin (lengkap)](#21-referensi-izin-lengkap)
- [22. Peta Migrasi](#22-peta-migrasi)
- [23. Deploy & Status Lokal vs Prod](#23-deploy--status-lokal-vs-prod)
- [24. Catatan & Utang Teknis](#24-catatan--utang-teknis)

---

## 0. Ringkasan

**SKINKU B2B Distributor Portal** — aplikasi Laravel untuk mengelola bisnis skincare SKINKU secara menyeluruh: penjualan B2B lewat jaringan mitra (MLM), penjualan marketplace (TikTok Shop + Shopee), stok, akuntansi buku besar, laporan, dan sejumlah alat produktivitas internal (OKR, Kanban, Mindmap, KOL, AI Assistant, bot Telegram).

- **Stack:** Laravel 13, PHP 8.3, Blade + JavaScript vanilla + Eloquent. Database SQL (MySQL prod / SQLite test).
- **Zero-dependency:** TIDAK memakai paket Composer tambahan di luar framework. Excel, HTTP, parsing — semua ditulis helper minimal sendiri (`XlsxWriter`, `SpreadsheetReader`, `Http` facade). Deploy = `git pull` saja, tak pernah `composer install` paket baru.
- **Dua kelas pengguna:** **staf HQ** (super_admin/admin/gudang) dan **mitra** (jaringan MLM: grand distributor, distributor, reseller, dsb). Middleware `internal` memisahkan fitur yang mitra tak boleh lihat sama sekali.
- **Pola deploy:** Claude push dari lokal → user `git pull` + `migrate` di server prod. (Detail: [§23](#23-deploy--status-lokal-vs-prod).)

---

## 1. Konvensi Arsitektur

Pola yang dipakai konsisten di seluruh kode — kenali sekali, berlaku di mana-mana:

| Pola | Maksud |
|---|---|
| **Service layer** | Logika bisnis hidup di `app/Services/*Service.php`, bukan di controller. Controller tipis: validasi input + panggil service + render view. |
| **Server-priced** | Harga & angka uang selalu dihitung ulang di server dari sumber master; input klien tak pernah dipercaya (kecuali override backdate eksplisit oleh staf). |
| **Preview → approve** | Aksi berisiko (potong stok, posting jurnal) punya langkah *preview* (dry-run, tampilkan dampak) sebelum eksekusi. Default manual; ada toggle auto per-koneksi. |
| **Append-only ledger** | Komisi, stock movement, jurnal — tak pernah di-*edit*/hapus untuk koreksi. Koreksi = tulis baris kompensasi (negatif) baru. Jejak audit utuh. |
| **Idempotent sync** | Semua sinkronisasi marketplace aman diulang: `updateOrCreate` by ID unik, guard cutoff/status, dan penanda "sudah diproses". |
| **Double-entry** | Semua peristiwa keuangan lewat satu pintu `AccountingService::record()` yang wajib balance (debit = kredit). |
| **Izin config-matrix** | Bukan paket. `app/Support/Permissions.php` (DEFINITIONS + DEFAULTS) + tabel `role_permissions` (hanya menyimpan *override*). super_admin selalu punya semua izin. |
| **Idempotent seeder** | Seeder (mis. COA) `upsert` by kode — aman dijalankan ulang, tak menimpa flag `is_active`. |

**Struktur folder inti:**
- `app/Models/` — Eloquent model + konstanta domain (status, tipe, role).
- `app/Services/` — logika bisnis (termasuk sub-namespace `Ai/`, `ReportBot/`).
- `app/Http/Controllers/` — endpoint web.
- `app/Http/Middleware/` — `RoleMiddleware`, `PermissionMiddleware`, `InternalOnlyMiddleware`.
- `app/Support/` — konstanta/util lintas modul (`Permissions`, `PartnerHierarchy`, `Costing`, `GrandPriceList`).
- `app/Console/Commands/` — artisan command (sync marketplace, reconcile, purge).
- `routes/web.php` — semua route web (dikelompokkan per izin).
- `routes/console.php` — jadwal cron.
- `database/migrations/` — 95+ migrasi (lihat [§22](#22-peta-migrasi)).

---

## 2. Auth, User, Role & Izin

**Tujuan:** identitas untuk staf HQ dan mitra MLM, dengan gating berbasis role + matriks izin yang bisa di-override per-role.

### Model & tabel
- **`User`** (`users`) — migrasi `0001_01_01_000000` + alter `000074`/`000082`/`000087`/`000092`.
  - Konstanta role: `super_admin, admin, gudang, distributor, reseller, grand_distributor, reseller_bronze, reseller_gold, sponsor`.
  - `PARTNER_ROLES` = 6 role mitra terakhir. (Konstanta lama `ROLES` cuma memuat 5 role awal — **tidak lagi otoritatif**; sumber role otoritatif = tabel `roles`.)
  - Field pohon: `upline_id` (rantai pasok), `sponsor_id` (rantai rekrutmen), `member_id` (`SKN-######`).
  - Helper: `isSuperAdmin/isAdmin/isManagement/isGudang/isStaff/isPartner/canDo()/priceField()`.
- **`Role`** (`roles`) + **`RolePermission`** (`role_permissions`) — `roles` seed 5 role awal (`000014`), lalu `000074` menambah grand_distributor/reseller_bronze/reseller_gold, `000075` menata ulang urutan. `role_permissions` hanya menyimpan baris **override** yang beda dari default.

### Logika izin
`app/Support/Permissions.php`:
- `DEFINITIONS` — ~38 kunci izin (dengan label & grup).
- `DEFAULTS` — peta kunci → role default pemilik.
- `roleHas($role, $key)` — super_admin selalu `true` (terkunci); selain itu baris override menang, kalau tak ada pakai `DEFAULTS`.
- Membekingi matriks admin di `/permissions`.

### Middleware
- **`RoleMiddleware`** (`role:...`) — juga otomatis logout akun yang dinonaktifkan/dihapus di setiap request.
- **`PermissionMiddleware`** (`permission:key`) → `$user->canDo(key)`.
- **`InternalOnlyMiddleware`** (`internal`) — **hard-block** `isPartner()` di atas matriks izin. Pertahanan berlapis untuk Kanban/Mindmap/OKR/Pengetahuan-AI: mitra tak pernah tembus walau matriks salah set.

### Alur
Login (`AuthController`, SQL murni, terima username / email / member_id) → seluruh `routes/web.php` dibungkus grup middleware `auth + role` → tiap fitur digating grup `permission:*` → `/permissions` (izin `manage_permissions`) memungkinkan super_admin edit matriks + tambah/tata-ulang/hapus custom role.

**Impersonation:** `ImpersonationService` — super_admin bisa "login sebagai" mitra tanpa password (session key `impersonator_id`). Mulai digating `role:super_admin`; berhenti sengaja **tak** digating (cukup session key) supaya mitra yang di-impersonate tak pernah terjebak.

> ⚠️ **Catatan:** `ROLE_SPONSOR` ('sponsor') dipakai logika komisi tapi **belum ada migrasi/seeder** yang memasukkan baris `sponsor` ke tabel `roles` → belum bisa dipilih via UI Role/Permission. (Lihat [§24](#24-catatan--utang-teknis).)

---

## 3. Hirarki MLM (2 pohon)

**Konsep kunci: ada DUA pohon terpisah di atas tabel `users`.**

1. **Pohon pasok** (`upline_id`) — siapa memasok barang ke siapa. Tulang punggung tier di `app/Support/PartnerHierarchy.php`:
   ```
   grand_distributor (L1)  →  distributor (L2)  →  { reseller_bronze, reseller_gold } (L3)
   ```
   Tiap tier punya flag `holds_stock`. `allowedParentRoles()` memaksa parent tepat **satu tingkat di atas** (tak boleh lompat).
2. **Pohon rekrutmen** (`sponsor_id`) — siapa merekrut siapa. Dipakai untuk bonus join & RO-cashback, **bukan** aliran barang.

### Service
- **`PartnerHierarchyService`** — `assignUpline()` (validasi tepat satu tier, no self-reference), `descendants()` (BFS, aman-loop), `generateMemberId()`.

### Alur penempatan
Admin onboard mitra via `/onboarding` (izin `manage_users`) → pohon bisa diedit di `/struktur-jaringan` (drag-drop `place()`/`changeTier()`, diblok kalau masih ada downline aktif) → mitra lihat subtree sendiri di `/jaringan-saya` dan rekrutannya di `/rekrutan-saya` (kedua-duanya digating `isPartner()` di controller, bukan via route permission).

> **Sejarah arah model** (konteks, bukan kode aktif): model sempat berpindah dari "override naik-pohon" → "komisi terpusat 1-tingkat" (Agu 2026) → arah "Model A / margin antar-mitra". Yang **aktif di kode sekarang**: PO antar-mitra (`seller_id`) hidup, override komisi **dorman** (rate default 0). Detail komisi di [§4](#4-komisi-withdraw-onboarding).

---

## 4. Komisi, Withdraw, Onboarding

**Tujuan:** ledger komisi append-only, penarikan saldo mitra, dan onboarding berbasis paket.

### Model & tabel
| Model | Tabel (migrasi) | Catatan |
|---|---|---|
| `Commission` | `commissions` (`000081`) | **Append-only.** `type` ∈ `override` / `join` / `ro_cashback` / `volume_bonus`. `status` = `saldo` (tak pernah benar-benar flip ke 'ditarik' walau komentar kolom bilang begitu). |
| `Withdrawal` | `withdrawals` (`000083`) | Status: `diajukan` → `disetujui`/`ditolak` → `cair`. |
| `JoinPackage` + `JoinPackageItem` | `000084` / `000085` | Bundel produk berharga untuk onboarding. |
| `JoinTransaction` | `000086` (+`000091` `cancelled_at`) | Catatan 1 transaksi onboarding. |
| `VolumeIncentiveTier` | `000088` | Tingkatan insentif volume tahunan GD. |

### Service komisi — `CommissionService`
- **Model A, override 1-tingkat, dorman default** (`RATE_DEFAULTS` override = 0.0, tetap bisa dihidupkan via `AppSetting`).
- `recordForCompletedPo()` — jalan **hanya** untuk PO HQ-direct yang `completed` (idempotent via `source_po_id`):
  1. **RO-cashback** — `sponsor_id` pembeli GD dapat **5%** dari nilai restock GD ke HQ (live/default-on).
  2. **Override** 1-tingkat ke upline langsung pembeli (dorman/0 default).
- `recordJoinBonus()` — bayar **sponsor** (bukan upline pasok) **10%** harga paket saat onboarding.
- Clawback = tulis baris negatif, tak pernah edit history.
- `availableBalance()` = saldo − withdrawal yang belum ditolak (beginilah withdrawal pending "mengunci" dana).

### Service insentif volume — `VolumeIncentiveService`
Tahunan, bertingkat, top-up untuk GD: entitlement = belanja-HQ bersih year-to-date × rate tier tertinggi yang tercapai; hanya bayar **delta** vs yang sudah dibayar (idempotent, bisa clawback kalau retur mengurangi total).

### Service onboarding — `OnboardingService`
`onboard()` = **satu transaksi atomik**: cek stok HQ → buat User (role = `package.target_role`, set `sponsor_id`) → assign upline + member_id → buat `JoinTransaction` → potong stok HQ per item paket → bayar bonus join. All-or-nothing. Kebalikannya: `ReturService::cancelJoin()` (restock HQ, claw back bonus, tandai cancelled).

### Alur withdraw
Mitra ajukan di `/komisi-saya` (min Rp100k, row-locked cegah double-submit) → HQ proses antrian di `/penarikan` (izin `process_withdrawal`): `diajukan` → `disetujui`/`ditolak` → `cair` (state terminal dipaksa).

**Izin:** `manage_users` (onboarding/struktur), `process_withdrawal` (penarikan), `view_commission_report` (laporan komisi — dipisah dari `view_reports` karena data payout lebih sensitif), `manage_join_packages` (katalog paket).

**Cron:** tidak ada — evaluasi komisi/volume sinkron di dalam `PurchaseOrderService::complete()`.

---

## 5. Purchase Order (PO)

**Tujuan:** inti transaksional. Mitra order ke HQ atau (Model A) ke upline langsung; menggerakkan stok riil dan memicu komisi.

### Model & tabel
- **`PurchaseOrder`** (`purchase_orders`, `000002` + alter `000008`/`000052`/`000080`):
  - `seller_id` — `null` = beli ke HQ, selain itu = beli ke upline.
  - `STATUSES` = `draft / pending / approved / processing / shipped / completed / cancelled`, dengan graf `TRANSITIONS` **maju-saja** (forward-only).
  - `PAYMENT_*` = `unpaid / awaiting_verification / paid / rejected`; `is_tempo` untuk tempo/cicilan.
  - `isEditable()` = `pending`/`draft` **dan** `unpaid`.
- **`PurchaseOrderItem`** — snapshot baris (harga dikunci saat order).
- **`PoReturn` / `PoReturnItem`** (`po_returns`, `000090`) — `kondisi` = `normal` (restock) vs `rusak` (write-off).

### Service — `PurchaseOrderService`
- `createForPartner()` — **server-priced** (`Product::priceForRole`). `resolveSeller()`: pembeli dapat seller antar-mitra **hanya jika** role-nya holds-stock (distributor/GD) **dan** punya upline yang juga holds-stock; selain itu → HQ. Reseller & GD tanpa stok selalu beli HQ.
- `updateStatus()` / `advanceStatus()` — memaksa `TRANSITIONS`; gate pembayaran memblok `processing`/`shipped`/`completed` kecuali `paid` atau tempo. `advanceStatus()` melangkah lewat tiap status antara (dipakai aksi massal + fulfil downline).
- `complete()` — **row-locked, guard double-complete**:
  - Lewati stok untuk PO HQ ber-tanggal sebelum cutoff opname (`AppSetting::PO_DEDUCT_FROM`) — sudah dihitung opname.
  - Selain itu: potong sumber (HQ `hq_stock` atau `inventory` seller) + kredit `inventory` pembeli, tulis `stock_movements`, lalu panggil `CommissionService::recordForCompletedPo()` + `VolumeIncentiveService::evaluate()` (bisa di-skip via `$recordCommission=false`, dipakai backfill sale backdate).
- `purge()` — hard-delete "seolah tak pernah ada" untuk data test (balik net stok, diblok kalau bikin saldo negatif / bentrok opname). **CLI-only** via `PoPurgeCommand`.

### Alur
`create_po` → `pending` → (jalur HQ) staf ber-`update_po_status` menjalankan status/verifikasi bayar/kirim/tempo di `/purchase-orders/*`; (jalur antar-mitra) upline ber-`process_downline_po` (+ guard `seller_id===me` di `DownlineOrderController`) verifikasi & fulfil di `/pesanan-downline/*`. `complete()` menggerakkan stok + memicu komisi. Retur via `/retur` (kepemilikan untuk buat, `process_return` untuk approve/reject; `super_admin` dicek di controller untuk void). `ReturService::apply()`/`void()` membalik stok + claw back/restore komisi proporsional + re-evaluasi volume GD.

**Izin:** `create_po`, `update_po_status`, `delete_po`, `process_downline_po`, `process_return` (routes/web.php ~L98-155).

---

## 6. Produk & Harga

**Tujuan:** katalog dengan harga 4-tier dan COGS rata-rata bergerak (moving average).

### Model — `Product` (`products`, `000001` + `000076` price_grand)
Kolom harga: `price_grand, price_distributor, price_reseller, price_retail`, plus `cogs, hq_stock, sku, status`.

`priceForRole($role)` — **sumber kebenaran harga tunggal** (dipakai `PurchaseOrderService::buildItemLines()`):
| Role | Harga |
|---|---|
| grand_distributor | `price_grand ?? price_distributor` |
| distributor | `price_distributor` |
| reseller / bronze / gold | `price_reseller` |
| lainnya | `price_retail` |

### COGS (HPP)
`cogs` bersifat **turunan**: setiap Stock Receipt (`StockReceiptService::receive()`) menghitung ulang sebagai rata-rata tertimbang bergerak terhadap harga masuk. Produksi (`ProductionService`) juga menyumbang HPP barang jadi. Riwayat HPP per-produk di `/products/{product}/hpp`.

**Izin:** `manage_products` (katalog), `manage_production` (material/supplier/produksi/HPP), `receive_stock` (penerimaan stok — izin terpisah dari manajemen stok HQ umum).

> ⚠️ **Catatan:** `User::priceField()` (helper display 2-tier) tak simetris sempurna dengan `Product::priceForRole()` (4-tier, otoritatif). Rumus moving-average juga terduplikasi (`app/Support/Costing.php` + inline di `StockReceiptService`).

---

## 7. Inventory / Stok

**Tujuan:** stok dua-ledger — satu kolam HQ (`products.hq_stock`) + kolam per-mitra (baris `inventory`) — dengan tiap perubahan dicermin ke jejak audit `stock_movements` yang **immutable**, yang jadi sumber semua laporan stok.

### Model & tabel
- **`Inventory`** (`inventory`, `000004`, unik `[user_id, product_id]`). `user_id` null = HQ.
- **`StockMovement`** (`stock_movements`, `000005`) — **tanpa `updated_at`** (append-only by design). `before_qty`/`after_qty` adalah yang direkonsiliasi laporan (bukan `quantity`).
  - `TYPES`: IN / OUT / ADJUSTMENT / TRANSFER / PO_FULFILLMENT (kolom **tak** enum-DB, jadi ada juga `'paket_join'` dari onboarding).
  - `reference_type` yang teramati: `purchase_order`, `po_return`, `join_transaction`/`join_cancel`, `opname`, `partner_sale`, `stock_receipt`, `production`, `tiktok_order`, `shopee_order`.
- **`StockReceipt` / `StockReceiptItem`** — header/baris penerimaan barang dengan snapshot cogs sebelum/sesudah.

### Stok minimum pusat (pengingat HQ menipis, migrasi `000160`)
- **Saran Stok Min.** (2026-10-08): `HqStockReportService::saranStokMinimum()` = barang keluar HQ 30 hari terakhir ÷ 30 × **14 hari cadangan** (dibulatkan ke atas; konstanta `SARAN_HARI_DATA`/`SARAN_HARI_CADANGAN`), kategori sama dgn Laporan Stok HQ (TikTok + Shopee + reseller + keluar lain; masuk, penyesuaian/opname, transfer tak dihitung). Tabel Produk Master: tautan "saran N" di bawah kolom Stok Min. (klik → isi & simpan otomatis; tooltip rata-rata/hari) + tombol **"Isi Stok Min. dari saran (N)"** (`POST products.min-stock.saran`) yang hanya mengisi produk aktif yang minimumnya masih kosong, audit `update_product_min_stock` (sumber "saran"). Test: `StokMinimumHqTest`.
- **Sort per kolom** di Produk Master (`?sort=&dir=`, daftar putih; sort HPP hanya utk `view_hpp`), Pemantauan Stok (tabel Stok Pusat `hq_sort/hq_dir`, tabel stok mitra `sort/dir` — terpisah) dan katalog Produk Master E-commerce (Nama/SKU/Harga/Stok; bervarian = harga termurah/total stok, bundle = stok hitungan, kosong selalu di bawah). Test: `SortTabelTest`.
- `products.hq_min_stock` (nullable; kosong = tanpa pengingat), diisi di form Produk Master (`manage_products`) **atau langsung di kolom "Stok Min." tabel Produk Master** (tersimpan tanpa reload saat pindah kolom/Enter/tombol simpan di sebelahnya — tombol utk HP; Enter langsung lompat ke produk berikutnya ala spreadsheet: `PATCH products.min-stock` → JSON, validasi manual 422, tercatat di Audit Log `update_product_min_stock`). `Product::isStokPusatMenipis()` = minimum > 0 & `hq_stock` ≤ minimum; scope `Product::stokPusatMenipis()` = itu + status aktif.
- Banner **"Stok pusat menipis"** di Dashboard (staf `manage_hq_stock` / `manage_products`, urut stok terkecil, 5 teratas + "Lihat semua" → Produk Master `?stok=menipis`); tanda "menipis · min X" di Produk Master & tabel Stok Pusat (Pemantauan Stok), badge di Laporan Stok HQ; Asisten AI: `pemantauan_stok` (stok_pusat + `stok_pusat_menipis`) & `produk_master` (`stok_minimum`, `stok_menipis`). Panel "Peringatan Stok Rendah" (stok mitra) kini juga mengabaikan minimum 0. Tak ada notifikasi push (sengaja; bisa ditambah lewat Report Bot Telegram). Test: `tests/Feature/StokMinimumHqTest.php`.

### Service
- **`InventoryService`** — **satu-satunya jalur tulis stok**. Tiap method `DB::transaction` + `lockForUpdate()`, selalu memasangkan tulis-saldo dengan `writeMovement()`.
  - `adjustHqStock()` / `adjustPartnerStock()` (berbasis delta, throw kalau negatif).
  - `setPartnerStock()` / `bulkSetPartnerStock()` (pola "deklarasikan hitungan riil, sistem hitung delta", race-safe di bawah lock).
  - `setPartnerMinimum()` (ambang saja, tanpa movement).
- **`StockReceiptService`** — posting penerimaan: naikkan `hq_stock`, hitung ulang `cogs` (moving avg), snapshot cost per baris.
- **`HqStockReportService`** — **Laporan Mutasi Stok HQ** (harian/bulanan). Rumus: `Stok Akhir = Stok Awal + Produksi + Penyesuaian − (TikTok + Shopee + Reseller + lain)`, seluruhnya diturunkan dari delta `stock_movements` yang dihitung mundur dari `hq_stock` sekarang (**selalu balance** apa pun urutan tulis). Bucket: produksi, masuk_lain, tiktok, shopee, reseller, keluar_lain, penyesuaian. Baseline = movement `opname` paling awal.
- **`StockOpnameController`** (izin `manage_hq_stock`) — hitung fisik vs delta sistem → `adjustHqStock(referenceType:'opname')`, di-backdate ke 23:59:59 hari sebelumnya → menetapkan baseline saldo awal yang dipakai `isBeforeStockCutoff()` (via `AppSetting::PO_DEDUCT_FROM`) supaya sale backdate tak dobel-potong.
- **`StockReconcileHqCommand`** (`stock:reconcile-hq`, CLI) — deteksi/perbaiki drift antara `hq_stock` dan movement terakhir; sengaja **tak** menulis movement koreksi (log ke Audit); bukan pengganti opname fisik.

### Alur
Stok **masuk** via Stock Receipt (`receive_stock`), Produksi (`manage_production`), adjust manual HQ / Opname (`manage_hq_stock`) → **pindah** HQ→mitra atau upline→downline eksklusif via `PurchaseOrderService::complete()` → **keluar** via potong sync marketplace, nota penjualan mitra→pelanggan (`PartnerSaleService`), retur PO, atau adjust manual mitra. Semua jalur lewat `InventoryService::writeMovement()`.

**Izin:** `manage_hq_stock` (adjust HQ, list movement, opname, laporan/ekspor HQ, sale backdate), `receive_stock` (penerimaan), `manage_production`. Route stok/nota sisi-mitra auth-only, di-scope kepemilikan di controller.

---

## 8. Integrasi TikTok

**Tujuan:** hubungkan 1 TikTok Shop (OAuth TikTok Shop Open API v2), tarik order otomatis, potong stok HQ, proses retur, tarik pencairan (settlement), dan opsional posting jurnal akuntansi double-entry. Ada juga fitur laporan terpisah (upload file) yang merekonsiliasi ekspor "Income" TikTok sendiri.

### Model & tabel
| Model | Tabel | Kolom kunci |
|---|---|---|
| `TiktokConnection` | `tiktok_connections` | `shop_id`, `shop_cipher`, token (hidden), `access_expires_at`, `auto_deduct`, `deduct_from`, `journal_enabled` |
| `TiktokOrder` | `tiktok_orders` | `tiktok_order_id` (unik), `status`, `total_amount`, `hpp_amount`, `line_items`, `stock_status`, `order_created_at`, `transit_journal_id`, `sale_journal_id` |
| `TiktokReturn` | `tiktok_returns` | `tiktok_return_id`, `review_status` (pending/restocked/rejected) |
| `TiktokSettlement` | `tiktok_settlements` | `tiktok_statement_id`, `revenue_amount`, `fee_amount`, `adjustment_amount`, `settlement_amount`, `kind`, `posting_status`, `journal_id` |
| `TiktokSkuMap` | `tiktok_sku_maps` | `tiktok_sku` → `product_id` × `qty` ("resep": 1 SKU TikTok → N komponen produk) |

Migrasi `000030`–`000040`.

### Service
- **`TikTokClient`** — wrapper API mentah. Tanda tangan HMAC-SHA256 (**sort SEMUA query param** by key, bungkus app_secret). `getToken/refreshToken/getShops/searchOrders/searchReturns/getStatements/getStatementTransactions`.
- **`TikTokSyncService`** — orkestrator dipakai tombol UI **dan** cron. `syncOrders()` (paginasi, filter `update_time_ge` + buffer overlap 2 jam, maks 60×100), `backfillOrders()` (rentang tanggal penuh, maks 400 halaman), `syncReturns()`, `syncSettlements()`, `describeSettlements()` (isi "kind" — 1 panggilan API per statement), `freshToken()`.
- **`TikTokOrderService`** — `store/normalizeItems/resolve` (SKU→resep: map manual dulu, else match `Product.sku`), `preview` (dry-run dampak stok + flag all_matched), `deduct` (idempotent; cek status shipped + cutoff + SKU lengkap; kunci `hpp_amount`), `deductAllReady`, `reverse`, `stockFunnel`, `cutoff/isBeforeCutoff`.
- **`TikTokReturnService`** — `store/preview/restock` (approve→+stok, idempotent) / `reject` (cacat→no-stok, tarik-balik kalau sebelumnya restocked) / `resetReview`.
- **`TikTokSettlementService`** — `store`, `kindFromStatement`, `deriveKind`, `translateType` (peta label EN→ID).
- **`TikTokAccountingService`** — mesin jurnal "Opsi C" akrual 3-tahap (lihat alur). `accounts/postTransit/postSale/postSettlement/preview/postPending/unpostAll/enabled`.
- **`TikTokIncomeReportService`** — fitur upload-file (lihat bawah).

### Alur
1. **Connect (OAuth)** — `/tiktok/connect` → redirect authorize → `/tiktok/callback?code=` → tukar token, ambil shop/`shop_cipher`, upsert koneksi.
2. **Sync order** — tarik order baru (atau jendela `update_time_ge` untuk tangkap perubahan status order lama) → `updateOrCreate` → kalau `auto_deduct` → `deductAllReady()`.
3. **Potong stok** — hanya `SHIPPED_STATUSES` (AWAITING_COLLECTION/IN_TRANSIT/DELIVERED/COMPLETED), hanya setelah `deduct_from`, hanya kalau semua SKU resolve. Kunci `hpp_amount` saat potong. Manual per-order / "potong semua" juga ada (pola preview-approve; UI SKU map di `/tiktok/orders`).
4. **Retur** — disync terpisah, review manual: `restock` (jual lagi → +stok) vs `reject` (cacat → no-stok). Stok saja, tak sentuh akuntansi.
5. **Settlement (pencairan)** — list disync (agregat read-only dulu); `kind` diisi lazy per-statement via panggilan detail (`tiktok:describe`) karena mahal 1 panggilan API tiap-tiap.
6. **Jurnal ("Opsi C" — akrual 3-tahap):**
   - Tahap 1 (barang keluar): `Dr Persediaan Dalam Perjalanan (1203) / Cr Persediaan Barang Jadi (1202)` — pindah aset, nol dampak L/R.
   - Tahap 2 (DELIVERED/COMPLETED): `Dr Piutang TikTok (1103) / Cr Penjualan (4001)` (gross) + `Dr Beban HPP (5003) / Cr 1203`.
   - Tahap 3 (settlement/cair): `Dr Kas TikTok (1003) net + Dr Beban Biaya E-commerce (6005) fee / Cr Piutang TikTok (1103)` gross; potongan murni (iklan/ongkir) `Dr Beban Iklan (6001)`/`Beban Ongkir (6007) / Cr Kas`.
   - Saklar `journal_enabled` (default **OFF**) + cutoff `deduct_from`. `postPending()` driver batch idempotent; `unpostAll()` balik penuh (hapus jurnal `source_type IN (tiktok_order_transit, tiktok_order_sale, tiktok_settlement)`, reset pointer).
7. **Laporan Income-upload** (Fase 1, migrasi dari bot n8n): upload CSV "Semua pesanan" + xlsx "income" → join by Order ID → qty diagregasi per kategori produk pakai resep `resolve()` → Excel diunduh. Report-only, session-stored, tak sentuh stok.

### Command / Cron
- `tiktok:sync [--returns] [--settlements] [--full]` — cron tiap 30 mnt (base); `--returns --settlements` harian 01:00; `--full` harian 03:30.
- `tiktok:describe [--limit=60]` — cron per jam (skip kalau tak ada backlog).
- `tiktok:backfill [--from=] [--to=]` — manual (isi celah historis).
- `tiktok:audit [--month=]` — diagnostik manual (rekonsiliasi total dashboard vs GMV Seller Center).

**Izin:** `manage_tiktok` (semua route `/tiktok/*`, default admin).

---

## 9. Integrasi Shopee (Fase 1–4)

**Tujuan:** pola sama dengan TikTok (sengaja "meniru TikTok yang sudah terbukti live"), diadaptasi ke Shopee Open Platform v2: token pendek (~4 jam), jendela list wajib ≤15 hari, dan model kas dua-tahap (escrow per-order untuk settlement, lalu ledger wallet terpisah untuk penarikan/iklan/penyesuaian).

**Status: Fase 1–4 SELESAI = 100% paritas TikTok + akuntansi penuh** (malah lebih akurat: fee escrow pasti per-order, bukan heuristik).

### Model & tabel
| Model | Tabel | Kolom kunci |
|---|---|---|
| `ShopeeConnection` | `shopee_connections` | `shop_id`, token, `access_expires_at` (~4j), `auto_deduct`, `deduct_from`, `journal_enabled` |
| `ShopeeOrder` | `shopee_orders` | `order_sn` (unik), `status`, `total_amount`, `hpp_amount`, `line_items`, `stock_status`, `transit_journal_id`, `sale_journal_id` |
| `ShopeeReturn` | `shopee_returns` | `shopee_return_sn`, `review_status` |
| `ShopeeSettlement` | `shopee_settlements` | `order_sn` (unik = **escrow per-order**), `escrow_amount`, `buyer_total_amount`, `commission_fee`, `service_fee`, `campaign_fee`, `actual_shipping_fee`, `escrow_tax`, dst., `posting_status`, `journal_id` |
| `ShopeeWalletTransaction` | `shopee_wallet_transactions` | `transaction_id` (unik), `transaction_type`, `kind`, `amount`, `current_balance`, `posting_status`, `journal_id` |
| `ShopeeSkuMap` | `shopee_sku_maps` | `shopee_sku` → `product_id` × `qty` |

Migrasi: `000042` (connections/orders/sku_maps), `000093` (returns), `000094` (settlements), `000095` (accounting: kolom jurnal + `journal_enabled` + tabel wallet).

### Service
- **`ShopeeClient`** — tanda tangan **beda dari TikTok**: `sign = HMAC-SHA256(partner_id + path + timestamp [+ access_token + shop_id], partner_key)` — nilai **dikonkat urut tetap, bukan di-sort**. `authorizeUrl/getToken/refreshToken/getOrderList` (wajib `time_range_field=update_time`, ≤15 hari), `getOrderDetail` (≤50/panggil), `getReturnList/getReturnDetail`, `getEscrowList/getEscrowDetail/getEscrowDetailBatch` (≤50/panggil), `getWalletTransactionList`, `getShopsByPartner` (publik, endpoint ping tanpa-shop). Helper `client()` memakai `withoutVerifying()` kalau `services.shopee.insecure` (dev lokal).
- **`ShopeeSyncService`** — `syncOrders()` (jendela ≤15 hari, clamp 14 hari kalau `last_synced_at` basi, cursor `order_sn`, detail chunk 50, auto-potong), `syncReturns()`, `syncSettlements()` (dua fase: `getEscrowList` discovery by release-time kumpulkan `order_sn` → `getEscrowDetailBatch` chunk 50 tarik rincian income), `syncWallet()`, `freshToken()`.
- **`ShopeeOrderService`** — kembar `TikTokOrderService`: `store/normalizeItems` (utamakan `model_sku` varian di atas `item_sku` induk), `resolve/preview/deduct/deductAllReady/reverse/skusNeedingMap`. Tulis movement `reference_type='shopee_order'`.
- **`ShopeeReturnService`** — `store/preview/restock/reject/resetReview`. Stok saja, tanpa akuntansi.
- **`ShopeeSettlementService`** — `store` + `mapIncome()` (peta sub-objek `order_income{}` ke kolom datar, 12 field defensif `?? 0`).
- **`ShopeeWalletService`** — `store` + `kindFromType()` (peta eksplisit `transaction_type` → label ID; 21 enum: escrow add/disburse → "Order cair", `WITHDRAWAL_COMPLETED` → "Tarik ke bank", `PAID_ADS_CHARGE` → "Biaya iklan", adjustment ±, dst.).
- **`ShopeeAccountingService`** (447 baris) — **4-tahap** posting (satu tahap lebih dari TikTok, karena Shopee pisahkan escrow per-order dari ledger wallet/bank): `accounts` (1001/1002/1104/1203/1202/4001/4002/5003/6005/6001/6007), `postTransit/postSale/postSettlement/postWallet/postPending/unpostAll/balanceOf`.

### Alur
1. **Connect (OAuth)** — `/shopee/connect` → `authorizeUrl(redirect)` → callback terima `code`+`shop_id` → `getToken` → upsert koneksi.
2. **Sync order** — pola idempotent + auto-potong sama TikTok, tapi dibatasi jendela bergulir ≤15 hari.
3. **Potong stok** — `SHIPPED_STATUSES = [SHIPPED, TO_CONFIRM_RECEIVE, COMPLETED]`; guard cutoff/mapping/idempotency sama TikTok.
4. **Retur** — review manual, stok saja, persis TikTok.
5. **Settlement (escrow per-order)** — ditemukan via `get_escrow_list`, rincian via `get_escrow_detail_batch`. Ini income bersih per-order Shopee.
6. **Wallet** — ledger terpisah pergerakan uang riil (iklan, tarik ke bank, penyesuaian), ditarik & di-posting independen, dengan logika **skip `ESCROW_*`** (dana itu sudah diakui via jurnal settlement).
7. **Jurnal (Opsi C, + wallet sbg tahap 4):**
   - Tahap 1 (barang keluar): `Dr 1203 / Cr 1202` (transit, nol L/R).
   - Tahap 2 (order COMPLETED): `Dr Piutang Shopee (1104) / Cr Penjualan (4001)` gross + `Dr Beban HPP (5003) / Cr 1203`.
   - Tahap 3 (escrow, per-order): `Dr Kas Shopee (1001)` net escrow + `Dr Beban Ongkir (6007)` + `Dr Beban Iklan (6001)` campaign + **baris plug `feeOther`** (`buyer_total − escrow − ongkir − campaign`, ke `Beban Biaya E-commerce (6005)` kalau positif / `Pendapatan Lain-lain (4002)` kalau negatif) — **jamin selalu balance** vs `Cr Piutang Shopee` (gross `buyer_total_amount` dari tahap 2).
   - Tahap 4 (wallet): `WITHDRAWAL_COMPLETED` → `Dr Bank (1002) / Cr Kas Shopee (1001)` (**model kas dua-tahap**: 1001 kas escrow-side, 1002 bank riil setelah tarik); iklan → `Dr Beban Iklan / Cr Kas`; adjustment dua arah.
   - Saklar `journal_enabled` OFF default, preview→post manual, `unpostAll` scoped `source_type IN shopee_*`.

**Tervalidasi data asli sandbox** (order `2608247FYHUBMG`): escrow 64675, buyer 77665, ongkir 11765 → jurnal balance (Dr Kas 64675 + Ongkir 11765 + fee 1225 = Cr Piutang 77665).

### Command / Cron
- `shopee:sync [--full] [--returns] [--settlements] [--wallet]` — base tiap 30 mnt; `--returns` 01:15; `--settlements` 01:30; `--wallet` 01:45.
- `shopee:ping [--insecure]` — diagnostik pra-go-live (panggil `get_shops_by_partner` publik, verifikasi partner_id/key/sign/base-URL tanpa connect toko).

**Izin:** `manage_shopee` (semua route `/shopee/*`, default admin).

**Env** (`config/services.php`): `SHOPEE_PARTNER_ID/PARTNER_KEY` + `SHOPEE_API_BASE`. Host sandbox BENAR = `openplatform.sandbox.test-stable.shopee.sg`; live = `partner.shopeemobile.com`. `SHOPEE_INSECURE` = bypass TLS **dev-only** (Windows lokal yang TLS-nya diintersepsi proxy/AV). Key format `shpk`+60hex dipakai **utuh** (jangan decode).

---

## 9b. Kalkulator ROI

**Tujuan:** alat bantu internal hitung biaya jual TikTok Shop per produk dan target ROI iklan — bukan bagian alur transaksi (tak sentuh PO/stok/akuntansi).

Tabel `roi_settings` (migrasi `000141`) — setelan **global** singleton (`id=1`, auto-dibuat dari `DEFAULTS` via `RoiSetting::current()`): persentase admin/voucher/komisi(+cap)/mall/pajak/operasional/affiliate + default packing/proses-order. Tabel `roi_items` (unik `product_id`) — baris per produk: `selling_price` + kolom override nullable (sama seperti settings, plus `modal`) yang menang atas global bila diisi; `modal` kosong → warisi `Product.cogs`.

`RoiCalculatorService::compute()` (murni, tanpa DB): Total Biaya = SUM(admin+voucher+komisi+proses_order+mall+pajak+operasional), Profit Bersih = (Harga−Modal)−Packing−Total Biaya, komisi dipotong `cap` (`min(raw,cap)`). BEP ROI = Harga/Profit Bersih; Target ROI @5/10/15/20% dihitung dgn & tanpa potongan affiliate; pembagi ≤0 → `null` (guard bagi-nol). `effectiveInputs()`/`rowFor()` gabung override+global jadi input efektif per-baris; `summary()` rata-rata Target Min(10%)/Optimum(20%) lintas semua baris (lewati null).

`RoiCalculatorController` (5 rute `roi-calculator.*`) → halaman `/kalkulator-roi`: setelan global + tabel produk (tambah dari katalog/edit override per-baris/hapus).

**Izin:** `manage_roi_calculator` (default admin, super_admin implisit). Menu sidebar berdiri sendiri "Kalkulator ROI".

---

## 9c. Portal Content Creator

**Tujuan:** creator (role `content_creator`) menyetor konten → admin review → portal menerbitkan ke **akun brand SKINKU** di Facebook Page, Instagram Business, Threads (API) dan TikTok (manual, Fase 2 via API). Spec: `docs/superpowers/specs/2026-09-25-content-creator/` (BRD/PRD/FRD/TRD).

Tabel (migrasi `000142`): `content_posts` (konten + status level konten), `content_post_targets` (status per platform: `external_id`, `permalink`, `attempts`, `next_attempt_at`, `container_id`), `social_connections` (satu akun per platform, token cast `encrypted`). Media = `files` polymorphic collection `content_media` via `ImageService` (gambar → JPEG maks 1440px, video apa adanya).

Alur status konten: `draft → in_review → (rejected ↺) → scheduled → publishing → done / partial / failed`; diturunkan dari status target oleh `ContentPost::recomputeStatus()`. Semua transisi + audit log di `ContentPostService` (submit/withdraw/approve/reject/retry/markPublished); validasi media/caption per platform di `ContentPostService::submitErrors()` + `config/content.php`.

Publikasi: cron `content:publish-due` tiap menit (inline, bukan queue job) → `Social\ContentPublisher` (FB sinkron; IG/Threads container → tunggu `FINISHED` → publish) lewat `Social\MetaClient` (Http facade, token via header Authorization). Gagal → retry 5/15/60 menit lalu `failed`; admin bisa retry / tandai terbit manual (tempel link). `social:refresh-tokens` harian memperpanjang token Threads & TikTok. **TikTok (Fase 2):** `Social\TikTokContentClient` — video FILE_UPLOAD berpotongan, foto PULL_FROM_URL; mode `auto` (API bila terhubung, selain itu manual); opsi privacy/interaksi dipilih reviewer saat approve (`content_post_targets.options`, migrasi `000143`). Halaman publik `/privacy` & `/terms` untuk review app. **Butuh `APP_URL` HTTPS publik** (IG/Threads mengambil media dari URL).

**Penilai AI (2026-10):** tombol "Nilai dengan AI" di form konten → `POST /content/ai-review` (`content.create`, throttle 20/menit) → `Ai\ContentReviewer`: menilai caption (teks saja) untuk TikTok & Instagram — skor 0–100 per platform, saran, usulan caption — memakai provider AI portal + pengetahuan `sistem`. Hanya saran: tidak menyimpan/menerbitkan; JSON rusak/error → pesan jelas (502), bukan skor karangan. Test: `tests/Feature/ContentAiReviewTest.php`.

**Insight (Fase 3, FR-80..84):** cron `content:sync-insights` harian 05:00 → `Social\ContentInsights` menarik metrik tiap target terbit ≤90 hari → `content_post_snapshots` (migrasi `000149`, unik per target per hari). Tampil di detail konten (kartu + grafik per platform), halaman **Insight Konten** (agregasi `KontenInsightService::ringkasan()`, dipakai juga alat AI `insight_konten`) (`/content-insights`, izin `content.review`: total views, ER, top postingan, performa per creator) dan kartu "Views 30 Hari" di dashboard creator. Scope insight baru → akun lama wajib dihubungkan ulang; error scope dicatat di `social_connections.meta.insight_error` tanpa memutus koneksi. Publish anti-dobel: `container_id`/`publish_id` hanya dikosongkan bila platform menolak, permalink best-effort.

**Cakupan Pipeline/Kalender:** `ContentPost::lintasCreator()` (super admin / `content.manage`) lihat semua creator, selain itu scope `terlihatOleh()` = hanya miliknya; saring tahap `scopeTahap()` / `scopePerluTindakan()` & angka kartu `ContentPost::hitungTahap()` — satu sumber utk halaman & alat AI `pipeline_konten`.

**Izin:** `content.create` (content_creator, admin), `content.review` (admin), `content.publish.manage` (admin), `social.connect` (super_admin saja). Pembuat ≠ penyetuju. Menu sidebar grup "Konten". Login `content_creator` di `/dashboard` → diarahkan ke `/creator`.

**Middleware `business`** (dibuat bersama modul ini): route PO/retur/inventory/penjualan mitra/komisi hanya untuk staff & mitra — sebelumnya role kustom non-staff (kol_specialist, affiliator) jatuh ke jalur staff dan melihat data semua mitra.

## 9d. Produk Master E-commerce

**Status:** Model **MANUAL ala Desty** SELESAI & **MERGED ke `main`** (`7b87bef`, LIVE; branch `feat/produk-master-manual`, 4 task SDD). Menggantikan model auto-dedup-by-nama (branch `feat/produk-master-katalog`, sempat merge & LIVE di `main` — tombol "Siapkan Master"/"Tarik Stok Awal (seed)"/"Buat master otomatis"/gabung-otomatis) yang hasilnya berantakan (banyak baris stok unmapped/harga kosong). Sekarang admin membuat & menautkan tiap master satu-per-satu, persis Desty. **Form Tambah/Ubah kini LENGKAP ala Desty** (kategori, deskripsi, galeri foto s.d. 9 dengan hapus/jadikan-utama, berat, dimensi, barcode) — SELESAI & MERGED ke `main` (branch `feat/produk-master-form-lengkap`, 3 task SDD, migrasi `000150`); detail di subseksi "Form Tambah/Ubah Produk Master (lengkap)" di bawah. **Field baru itu data internal SKINKU — yang di-push OTOMATIS ke marketplace tetap HANYA stok & harga** (deskripsi/berat/dimensi kini bisa didorong MANUAL lewat tombol "Dorong Konten" — branch `feat/dorong-konten-marketplace`, 5 task SDD, migrasi `000151`; lihat subseksi "Dorong Konten ke Marketplace (Fase 1)" di bawah).

**Tujuan:** kontrol stok+harga marketplace ala Desty — tiap unit jualan (satuan/varian/**bundle**) = 1 baris "Produk Master" dengan stok & harga **di-set langsung** (bukan diturunkan dari pool produk HQ), ditautkan manual ke listing TikTok/Shopee, dengan opsi override per-channel. **TERPISAH TOTAL dari stok HQ** (`hq_stock`/`stock_movements`/`InventoryService` tak pernah dibaca/ditulis oleh modul ini).

**Model manual (bukan auto):** master TIDAK lagi dibuat otomatis dari hasil resolve/refresh listing atau dedup by nama. Alurnya: (1) admin bikin master lewat **"+ Tambah Produk Baru"** — isi nama, master SKU, harga, stok, tipe Satuan/Bundle, kategori, deskripsi, foto (s.d. 9), berat/dimensi, barcode, semuanya manual; (2) admin tautkan master itu ke listing TikTok/Shopee yang sudah ada (hasil "Refresh Listing") lewat menu **Atur → "Tambah ke Marketplace"** supaya stok/harga mulai tersinkron ke channel itu. Dua master boleh punya nama sama — tak ada lagi enforce dedup by `name_key` di jalur manual (`name_key` cuma dipakai jalur tautkan-buat-master-baru di service, lihat bawah).

### Model & tabel
| Model | Tabel | Kolom kunci |
|---|---|---|
| `MarketplaceMaster` | `marketplace_masters` | `master_sku` (bebas isi manual, TAK unik & bukan lagi kunci dedup), `name`, `name_key` (dari `normalizeName()` — dipakai `findOrCreateMaster()` di jalur tautkan-buat-baru, TAK dipakai utk cegah nama kembar di form manual), `is_bundle` (dipilih admin di form Tipe; `detectBundle()` cuma jalan otomatis di jalur `findOrCreateMaster`), `image_url` (fallback kalau belum ada foto upload manual), `product_id` (nullable, referensi opsional saja — **bukan** sumber stok), `base_stock` (nullable = belum di-set → tak di-push), `base_price`, `seeded_at` (di-set oleh `setMasterStock`, jadi guard `applyOrderDelta` & reconcile), **kolom detail form lengkap (migrasi `000150`, semua nullable):** `category` (teks bebas), `description` (teks, validasi maks 8000 karakter), `weight_g` (gram), `length_cm`/`width_cm`/`height_cm` (cm), `barcode` — **data internal SKINKU**; hanya `description`/`weight_g`/`length_cm`/`width_cm`/`height_cm` + foto (koleksi `master_image`, Fase 2) yang bisa didorong MANUAL ke marketplace (Dorong Konten, subseksi di bawah) — `category`/`barcode`/nama TIDAK di-push |
| `MarketplaceMasterChannel` | `marketplace_master_channels` | `master_id`, `channel` (tiktok/shopee), `stock` (nullable = ikut Master), `price` (nullable = ikut Master), `seeded_at`; unik (`master_id`,`channel`) |
| `MarketplaceListing` | `marketplace_listings` (dari Fase 1) | `master_id` (nullable, FK ke master, `nullOnDelete` — cuma terisi lewat tautkan manual, TAK PERNAH oleh resolve/refresh), jejak push harga: `last_pushed_price`/`last_price_status`/`last_price_error`/`last_price_pushed_at` (jejak push stok — `last_pushed_qty`/`last_status`/`last_error`/`last_pushed_at` — sudah ada sejak Fase 1), jejak push **konten** (migrasi `000151`, semua nullable): `last_content_status` (`ok`\|`failed`)/`last_content_error`/`last_content_pushed_at` (waktu push SUKSES terakhir)/`content_hash` (md5 payload terkirim — diff-guard), jejak push **foto** (migrasi `000153`, semua nullable): `last_photo_status` (`ok`\|`failed`)/`last_photo_error`/`last_photo_pushed_at`/`photo_hash` (md5 daftar `[id, sort_order]` foto master terkirim — diff-guard) |

Migrasi (semua sudah ada sebelum rework manual ini — **tanpa migrasi baru**, skema sudah lengkap): `000139`/`000140` (tabel Fase 1 — **di-drop** oleh `000146`), `000145` (tabel `marketplace_masters`/`marketplace_master_channels` + kolom master/harga di `marketplace_listings`), `000146` (migrasi data Fase 1/1.5 lama ke Master lalu `dropIfExists`), `000147` (kolom `name_key`/`is_bundle`/`image_url` + reset/kosongkan master lama dari model auto sebelumnya — data mirror marketplace, aman dikosongkan), `000148` (drop unique `master_sku`, cegah bentrok SKU sama beda-judul lintas channel). Katalog dimulai kosong pasca `000147`; di model manual tak ada lagi auto-isi, jadi tetap kosong sampai admin input. **Satu-satunya migrasi tambahan dari fitur Form Lengkap:** `000150` (`add_detail_fields_to_marketplace_masters` — 7 kolom detail nullable di tabel di atas: `category` string, `description` text, `weight_g`/`length_cm`/`width_cm`/`height_cm` unsignedInteger, `barcode` string; `down()` men-drop ketujuhnya; tak menyentuh data yang sudah ada). Foto TIDAK butuh migrasi/tabel baru — memakai tabel `files` + trait `HasFiles` yang sudah ada (koleksi `master_image`).

Model helper (`MarketplaceMaster`): `normalizeName($name)` (lower+squish, dipakai bikin `name_key`), `detectBundle($name)` (regex `bundl|paket` — helper deteksi bundle dari nama; form manual pakai select Tipe eksplisit), `imageUrl()` (foto upload manual — koleksi `master_image` via trait `HasFiles` + `ImageService::attach()` — **menang** atas `image_url`, fallback ke situ kalau belum ada upload). Koleksi `master_image` kini boleh berisi **s.d. 9 foto**; foto berurutan `sort_order` terkecil = **foto utama** (yang dipakai `imageUrl()` / thumbnail katalog).

### Service — `MarketplaceMasterService`
- **Nilai efektif** — `effectiveStock`/`effectivePrice(MarketplaceMaster, channel)`: override channel menang bila non-null, jatuh balik ke `base_stock`/`base_price` Master, `null` bila keduanya belum di-set (anti-push).
- **Setter** — `setMasterStock`/`setMasterPrice` (Master langsung, stempel `seeded_at`); `setChannelStock`/`setChannelPrice` (override per channel, `updateOrCreate`); `ikutMaster(master, channel, field)` — kembalikan satu field (`stock`/`price`) ke ikut Master, hapus baris override kalau keduanya sudah null.
- **Duplikat** — `duplicateMaster(MarketplaceMaster): MarketplaceMaster` — gandakan `name` ("... (copy)"), `master_sku` ("...-COPY"), `is_bundle`, `base_price` ke master baru; TANPA listing/channel-override/foto/`base_stock`, dan kolom detail form lengkap (kategori/deskripsi/berat/dimensi/barcode) juga TIDAK ikut tersalin — mulai bersih, controller redirect ke form Ubah hasil duplikat supaya admin sesuaikan lalu simpan.
- **Refresh listing (TANPA auto-master)** — `resolveListings(channel)` HANYA upsert baris `marketplace_listings` (item_id/variation_id/warehouse_id/title) dari TikTok/Shopee; `master_id` listing baru dibiarkan `null` — tak auto-buat master seperti model sebelumnya (`RefreshNoAutoMasterTest`). Penautan ke master murni manual lewat modal **Kaitkan Produk** (bulk `kaitkan`/`lepas`, di bawah). (Jalur single lama `tautkanListing()`/`findOrCreateMaster()` + rute `tautkan` sudah **dihapus** — digantikan modal.)
- **Kaitkan/Lepas (bulk)** — `linkListings(MarketplaceMaster $m, array $ids): int` set `master_id` semua listing di `$ids` ke `$m` (tanpa cek pemilik lama — modal client-side men-disable checkbox listing milik master lain supaya tak ke-timpa tak sengaja); `unlinkListings(MarketplaceMaster $m, array $ids): int` set `master_id` ke `null` **hanya** listing yang memang milik `$m` (safety — listing milik master lain diabaikan diam-diam). Dipicu controller `kaitkan`/`lepas`: `kaitkan` langsung `pushMaster()` sesudahnya supaya stok/harga master segera terdorong ke listing baru tertaut; `lepas` tak push (listing sudah lepas, tak ada channel row terkait lagi).
- **Kosongkan semua** — `deleteAllMasters(): int` — hapus SELURUH `marketplace_masters` (bulk). FK: `marketplace_listings.master_id` auto-`null` (`nullOnDelete`), `marketplace_master_channels` auto-hapus (`cascadeOnDelete`). **HQ tak disentuh.** Dipicu controller `kosongkan` + tombol "Kosongkan Semua Master" (konfirmasi) di toolbar — untuk mulai bersih di model manual / membuang sisa master auto lama.
- **Push** — `pushListing(listing, force)` push stok & harga sebagai **dua unit independen** (anti-push-null masing-masing, skip kalau nilainya = push terakhir kecuali `force`); `pushMaster(master)` sinkron semua listing 1 master (dipanggil otomatis tiap admin set stok/harga master inline, dan tiap simpan form Ubah); `pushDirty()`/`pushAll()` sinkron semua listing bermaster (dipakai cron & tombol "Sinkron Semua").
- **Cermin order** — `applyOrderDelta(MarketplaceListing $listing, int $delta, Carbon $orderCreatedAt)`: turun/naikkan stok efektif (override channel dulu, jatuh balik ke `base_stock` Master) — hanya bila baris tujuan sudah `seeded_at` dan order terjadi setelahnya (order lama diabaikan). Dipanggil dari `TikTokOrderService`/`ShopeeOrderService` **berdampingan** dengan (bukan menggantikan) potong stok HQ via `InventoryService` — dua jalur independen yang tak saling baca.
- **Client** — `TikTokClient::updatePrice()` (`POST /product/202309/products/{id}/prices/update`) & `ShopeeClient::updatePrice()` (`POST /api/v2/product/update_price`), dipakai jalur push harga.
- **Dihapus dari model auto sebelumnya** (branch `feat/produk-master-katalog`, dibuang di `feat/produk-master-manual`): `siapkanMaster()`, `deleteOrphanMasters()`, `seedFromTiktok()`, `masterizeUnmastered()`, `mergeMaster()` — bahaya/berlawanan dg model manual (mis. `deleteOrphanMasters` bakal menghapus master baru admin yang belum sempat ditautkan).

### Halaman katalog (`/marketplace-stock`)
Tabel ala Desty — kolom **Foto · Informasi Produk (nama + badge BUNDLE) · Master SKU · Harga · Stok · Produk Terkait · Toko Terkait · Atur**. Harga/Stok = form inline (submit langsung `setMasterPrice`/`setMasterStock`, lalu push otomatis ke channel yang listing-nya sudah tertaut). "Produk Terkait" = jumlah listing tertaut (`listings->count()`); "Toko Terkait" = jumlah channel unik tertaut (`listings->pluck('channel')->unique()->count()`); keduanya "—" bila 0, dan kalau >0 **bisa diklik** (`onclick="mpOpenKaitkan(this)"`) buka modal "Kaitkan Produk" langsung pada tab "Produk Terkait" master baris itu.

- **Tab** `?tab=semua|satuan|bundle` (default `semua`) — filter kolom `is_bundle`, label tab menampilkan hitungan (`counts`).
- **Banner** ringkas kalau ada listing belum tertaut (`unlinkedCount`) — arahkan pakai "Tambah ke Marketplace" pada produk master yang sesuai untuk menautkan.
- **Atur** (dropdown per baris): **Ubah** (`edit`/`update`) · **Duplikat Produk** (`duplikat`) · **Tambah ke Marketplace** (buka modal "Kaitkan Produk" — lihat bawah, tab default "Semua") · **Jadikan Bundle/Satuan** (toggle, `master.bundle`) · **Hapus** (`master.hapus`, method `DELETE` + konfirmasi JS `confirm()`).
- **Empty state**: "Belum ada produk master. Klik + Tambah Produk Baru." — tanpa lagi tabel besar "listing belum termaster".
- Toolbar atas: **"+ Tambah Produk Baru"** (`create`) · **"⇪ Sinkron semua"** (`push-all`) · **"↻ Refresh Listing"** (`resolve`) · link ke halaman override per-channel TikTok/Shopee (`channel`).
- Form Tambah/Ubah (`marketplace-stock.form`, satu view utk `create`+`edit`) — form lengkap ala Desty, lihat subseksi di bawah.

### Form Tambah/Ubah Produk Master (lengkap ala Desty)
Satu view `marketplace-stock.form` (kontainer `max-w-3xl`) dipakai `create` (master baru, tanpa galeri) dan `edit`. **Simpan data di SKINKU; yang di-push OTOMATIS ke TikTok/Shopee tetap HANYA stok & harga** (mekanisme lama tak diubah). Kategori, deskripsi, foto, berat, dimensi, barcode = **data internal**; deskripsi/berat/dimensi bisa didorong MANUAL lewat kartu/tombol "Dorong Konten" (subseksi berikut) dan **foto ikut lewat tombol yang sama sejak Fase 2** (hanya bila set foto berubah), sedangkan nama/kategori/barcode belum. **Upload foto anti-timeout**: foto dikompres di browser dulu (canvas, maks 1600px, q0.85 → JPEG; submit ditahan sampai kompres selesai; fallback file asli bila browser tak mendukung), lalu server menyimpan foto master **1600px q85** (`ImageService::attach(..., 1600, 85)`; default pemanggil lain tetap 1280/q80). Tes: `FotoKompresTest`. **Input harga bertitik ribuan** (katalog, halaman channel, form master + harga varian + "harga semua varian"): `<input type="text" inputmode="numeric" data-rupiah>` diisi `App\Support\Rupiah::input()` ("195.000"); skrip `partials/rupiah-input` memformat saat diketik dan mengirim angka polos lewat event `formdata` (juga utk `form.submit()` skrip kompres foto); server menormalkan `price` & `varian.*.price` via `Rupiah::polos()` SEBELUM validasi — tanpa itu "65.000" lolos `numeric` sbg 65. Tes: `HargaRibuanTest`.

- **`<form>` utama** (`POST store` / `PUT update`, `enctype="multipart/form-data"`, `@csrf`) berisi 4 kartu: **Informasi Produk** (Nama, Kategori — teks bebas, Deskripsi — `<textarea>`), **Informasi Penjualan** (Harga, Stok — nullable, boleh kosong dulu mis. utk bundle; Master SKU; Barcode; Tipe `<select>` Satuan/Bundle), **Tambah Foto** (`<input type="file" name="foto[]" accept="image/*" multiple>` — banyak sekaligus, JPG/PNG maks 5MB/file), **Pengiriman** (Berat gram; Panjang/Lebar/Tinggi cm).
- **Galeri foto** (kartu "Foto Produk", **hanya tampil di Ubah**): grid thumbnail dari `$master->fileGallery(MarketplaceMaster::MASTER_IMAGE)`. Foto pertama diberi badge **Utama**. Tiap foto punya tombol **Hapus** (`DELETE marketplace-stock.master.foto.hapus`, konfirmasi JS `confirm()`, file fisik ikut terhapus lewat hook `deleting` model `File`) dan — kecuali foto utama — **Jadikan Utama** (`POST marketplace-stock.master.foto.utama`: file itu `sort_order` 0, sisanya digeser ≥1). Kalau belum ada foto: teks "Belum ada foto". **Atur urutan dengan geser (drag & drop)**: foto tersimpan bisa digeser (mouse: dari mana saja di foto; HP/sentuh: lewat ikon ⠿ supaya halaman tetap bisa di-scroll); urutan langsung disimpan via AJAX `POST marketplace-stock.master.foto.urutan` (`urutkanFoto`, body JSON `urutan` = id foto urut baru, pertama = Utama). Kiriman wajib **persis himpunan foto master itu** (kurang/lebih/duplikat/id master lain → **422**, sekaligus guard IDOR), ditulis dalam satu transaksi. Validasi sengaja manual (`abort(422)`), bukan `$r->validate()` — di app ini ValidationException rute web jadi redirect 302 yang diikuti `fetch()` sehingga gagal tampak sukses; JS juga menganggap `res.redirected` (sesi habis → login) sebagai gagal. Urutan baru mengubah `photo_hash`, jadi "Dorong konten & foto" berikutnya mengirim foto dengan urutan ini. **Foto Baru (belum di-Simpan) juga bisa digeser**, termasuk di form Tambah: bila ada foto Baru, urutan TIDAK di-AJAX tapi dicatat di input tersembunyi `urutan_foto` (token `f<id>` foto tersimpan, `n<i>` foto Baru ke-i di `foto[]`) lalu diterapkan `terapkanUrutanFoto()` setelah upload saat Simpan. Longgar: token asing/foto master lain/duplikat diabaikan, foto yang tak disebut ditaruh di belakang; foto Baru **ditampung** tiap kali pilih (bisa tambah 1-per-1; `input.files` dirakit ulang via DataTransfer — browser tanpa DataTransfer: pilihan baru menggantikan), tiap foto Baru punya tombol **Hapus** (batal sebelum Simpan), dan total tersimpan + Baru dibatasi 9 di browser.
- ⚠️ **Kartu galeri WAJIB berada DI LUAR `<form>` utama.** Tiap tombol Hapus/Jadikan Utama adalah `<form>` sendiri, dan HTML melarang `<form>` bersarang (browser membuang form dalam → tombol foto jadi ikut mengirim form update). Dijaga tes `test_form_edit_tak_ada_form_bersarang` (kedalaman `<form>` di area konten maks 1). Kelola foto lama = kartu galeri di atas; upload foto baru = kartu "Tambah Foto" di dalam form utama.
- **Batas & keamanan (controller `MarketplaceStockController`)**: `validateMaster` — `foto` array maks 9 file, `foto.*` `image` ≤5120 KB; `applyMasterInputs` menambah batas **TOTAL 9 foto per master** (kelebihan dari yang sudah ada diabaikan diam-diam, tak error); `deleteFoto`/`setFotoUtama` lewat guard IDOR `assertFotoMilikMaster` (file harus milik master di URL **dan** berkoleksi `master_image`, kalau tidak **404** tanpa efek samping); kolom baru diisi lewat `masterAttributes()` (angka kosong → `null`).
- **Simpan**: harga/stok tetap lewat `setMasterPrice`/`setMasterStock` kalau field diisi (supaya `seeded_at` ke-set), foto lewat `ImageService::attach()` (resize+kompres GD, tanpa paket baru), lalu — hanya saat Ubah — `pushMaster()` (stok & harga saja).
- **Flash pesan**: `session('status')` ("Foto dihapus.", "Foto utama diperbarui.") ditampilkan oleh `layouts.app`; view form TIDAK mengulangnya (jaga: `test_form_edit_flash_status_tampil_sekali`, kalau tidak banner muncul dobel tiap habis Hapus/Jadikan Utama).
- **Tes**: `CreateEditMasterTest` (store/update field lengkap, foto banyak + batas 9, render form Tambah & Ubah, galeri, guard form-bersarang & banner dobel) dan `FotoMasterTest` (hapus/jadikan utama, IDOR master lain/koleksi lain, non-izin 403), `FotoUrutanTest` (simpan urutan geser, tolak himpunan tak cocok/IDOR, 403, urutan campuran foto Baru saat Simpan/Tambah). Skrip geser diverifikasi di Chromium (mouse, sentuh via ⠿, klik biasa tak menyimpan, 422 & sesi habis tampil "Gagal").

### Pemetaan ke HQ (persiapan penggabungan stok — Tahap 1)

Halaman `marketplace-stock.pemetaan-hq` (GET/POST, izin `manage_marketplace_stock`, `MarketplacePemetaanHqController`, tombol "Pemetaan ke HQ" di katalog): satu tabel semua unit jual (induk bervarian dilewati, variannya tampil). **Hanya merapikan data Produk Master** — stok HQ, SKU map, marketplace & stok master tak disentuh.
- Satuan: dropdown produk gudang (`product_id`), pra-isi **tebakan** `tebakProdukHq()` bila belum ditandai (disorot kuning): (1) SKU listing = SKU resep tunggal ×1 di SKU map HQ, (2) SKU sama (master_sku/seller_sku = SKU produk), (3) nama mirip (`kataPenting`: ≥2 kata penting sama, satuan dibuang, seri → tak menebak).
- Satuan bernama bundling (`detectBundle`) → centang "Jadikan Bundle?".
- Bundle tanpa resep → pratinjau resep HQ + centang "Isi resep dari HQ" (tercentang bila lengkap).
- Simpan sekali: tanda produk gudang → Jadikan Bundle (penanda dikosongkan) → resep HQ (hanya bila SEMUA isi ketemu padanan; yang belum → flash error). Tes: `PemetaanHqTest`.
- Rencana gabung (Tahap 3): stok marketplace = stok HQ, resep tunggal, order potong HQ sekali, stok manual master dihapus. Konvensi: satu kode SKU per barang di HQ, Produk Master & Seller SKU marketplace.

### Stok Bundle Otomatis (Fase 6)

- **Resep** di tabel `marketplace_bundle_items` (migrasi **`000156`**: `bundle_id`, `component_id`, `qty`; unik per pasangan; FK cascade) — model `MarketplaceBundleItem`, relasi `MarketplaceMaster::bundleItems()` / `dipakaiBundle()`.
- **Stok bundle ber-resep DIHITUNG**: `effectiveStock(bundle, ch)` = `min(floor(effectiveStock(komponen, ch) / qty))` (override channel komponen ikut; komponen tanpa stok → null = tak di-push). `base_stock` bundle diabaikan selama ada resep; setel stok manual bundle ditolak.
- **Order**: `applyOrderDelta` listing bundle → `applyDeltaMaster` ke tiap komponen dgn `qty × delta` (guard `seeded_at` komponen; batal/retur = delta positif). Cron `marketplace:push-stock` menyusul lewat diff-guard; ubah stok komponen (inline & Simpan) langsung `pushBundleTerkait()`.
- **Form**: kartu "Isi Bundling" (tampil bila Tipe = Bundle; flag `isi_bundle_ada`; himpunan lengkap) — komponen = unit jual (`komponenOpsi`: bukan induk bervarian, bukan bundle ber-resep, bukan diri sendiri; disaring lagi di `simpanIsiBundle`, duplikat dijumlah). Tipe Satuan → resep dikosongkan. Perkiraan stok tampil live; input Stok manual disembunyikan bila ada isi.
- **Katalog**: kolom Stok bundle ber-resep = angka hitungan + label "otomatis" (bukan form). Hapus master yang masih jadi isi bundling ditolak.
- **Ambil resep dari HQ** (tombol di kartu Isi Bundling → JSON `marketplace-stock.master.resep-hq`, `resepDariHq()`): HANYA membaca resep HQ = SKU map (`tiktok_sku_maps`/`shopee_sku_maps`, SKU marketplace → produk HQ × qty; dipakai `TikTokOrderService`/`ShopeeOrderService` memotong stok HQ saat order dikirim). SKU dicoba dari listing bundle (TikTok dulu) lalu `master_sku`. Produk HQ → master satuan via (1) `product_id`, (2) listing yg SKU-nya resep tunggal produk itu ×1, (3) `master_sku` = SKU produk; bukan bundle/induk bervarian. Yang tak cocok dilaporkan + dibuatkan baris (qty terisi, produk dipilih manual, disorot). Penanda **"Produk HQ"** di form (`product_id`, hanya bila field dikirim) = master ini mewakili produk HQ mana → jalur cocok (1); HANYA penanda, stok belum disambung. Tujuan: resep Produk Master = resep HQ (dua buku stok terpisah; disambung penuh nanti).
- Belum: resep untuk opsi VARIAN (mis. varian "3 Pcs" = 3 × "1 Pcs") — sementara pakai master bundle terpisah. Tes: `BundleStokTest`.

### Varian Produk Master ala Desty (Fase 5)

- **Model**: varian = master ANAK (`marketplace_masters.parent_id`, migrasi **`000155`**; `variant_type` di induk mis. "Qty", `variant_name` di anak mis. "Scrub 3 Pcs"; FK `cascadeOnDelete` — hapus induk = hapus variannya, listing varian jadi tak tertaut via FK lama). Anak = baris master biasa → **stok/harga/cermin order/cron tak berubah** (semua lewat `listing.master_id`).
- **Konten level produk dari induk**: `MarketplaceMaster::sumberKonten()` (induk bila varian) dipakai `buildContentPayload` (nama/deskripsi/berat/dimensi/kategori), `photoHash`/`pushPhotos` (foto induk); **barcode tetap per varian**. `keluargaIds()` = induk + anak. Judul dikirim bila SEMUA SKU produk marketplace itu tertaut ke keluarga yang sama (`satuSku`); kategori ditahan hanya bila keluarga LAIN memilih kategori berbeda (`kategoriBoleh`). `pushMasterContent` & tombol Dorong (`pushKeluarga`) menjangkau seluruh keluarga.
- **Form** (kartu "Varian Produk", di dalam form Simpan, flag `varian_ada`): Tipe Varian + tabel opsi (nama, SKU, harga, stok, barcode, jumlah listing) + "Terapkan ke semua" + Tambah/Hapus opsi (konfirmasi bila opsi tertaut listing). `simpanVarian()`: daftar terkirim = himpunan lengkap (anak yang hilang dihapus), id dicocokkan hanya ke anak master itu (IDOR), harga/stok lewat setter hanya bila berubah. Master tunggal yang diberi varian pertama kali & sudah punya listing → listing **dipindah ke varian pertama** (+ warisi harga/stok induk bila kosong). Harga/Stok induk disembunyikan bila ada varian.
- **Katalog**: hanya master teratas (`whereNull('parent_id')`, termasuk hitungan tab); induk bervarian menampilkan rentang harga, total stok, jumlah listing varian; baris varian menempel di bawahnya (harga/stok inline + Atur: Tambah ke marketplace, Ubah induk). Partial `marketplace-stock/_kolom-stok`. Tautkan listing ke induk bervarian **ditolak** (harus per varian). Edit varian → redirect ke form induk.
- Belum: membuat/menambah varian BARU di marketplace via API (Tahap B), duplikat induk belum menyalin varian. Tes: `VarianMasterTest`.

### Sinkron Otomatis Konten & Foto tiap Simpan (Fase 3)

**Aturan sekarang (menggantikan "manual saja" di subseksi Fase 1/2 di bawah):**
- **Simpan form Ubah** → stok & harga (seperti dulu) **+ nama/deskripsi/berat/dimensi/barcode/foto** otomatis (`pushMasterContent($m, false, otomatis: true)`): diff-guard (hanya yang berubah) dan **hanya ke listing yang sudah pernah sukses didorong manual** per bagian (konten: `last_content_pushed_at`; foto: `last_photo_pushed_at`) — dorong pertama menimpa isi/SEMUA foto listing, jadi wajib disengaja lewat tombol. Gagal → flash `error`.
- **Tombol "Dorong"** (form & menu Atur) = **SEMUA dipaksa**: stok & harga (`pushMaster(force)`) + konten + **foto (dipaksa juga, walau tak berubah)**. Flash "foto diganti di N listing" = listing yang fotonya sukses diganti sejak awal request.
- **Nama** → TikTok `title` / Shopee `item_name`, **hanya bila produk marketplace itu cuma 1 SKU** (`satuSku`: tak ada listing lain dgn `channel`+`item_id` sama) — judul level produk, produk bervarian dilewati agar tak tertimpa nama satu varian.
- **Barcode** → hanya **GTIN valid** (`MarketplaceMasterService::gtin`: 8/12/13/14 digit + check digit); TikTok `skus[{id: variation_id, identifier_code{code,type: EAN|UPC|GTIN}}]`, Shopee `gtin_code` hanya item tanpa model (`variation_id` 0; per-model butuh `update_model` — belum).
- ⚠️ Belum terkonfirmasi di production: TikTok `partial_edit` menerima `title`/`skus[].identifier_code`, Shopee `update_item` menerima `item_name`/`gtin_code`. Bila ditolak → `last_content_status=failed` + pesan asli (seluruh payload konten listing itu tak masuk).
- **Kategori marketplace (Fase 4)** — kartu "Kategori Marketplace" di form (di DALAM form Simpan): pemilih **terpisah per channel** (pohon & ID TikTok ≠ Shopee). Kolom master (migrasi **`000154`**): `tiktok_category_id/_name/_attributes`, `shopee_category_id/_name/_attributes`, `shopee_brand`; atribut tersimpan ternormalisasi `[{id, values:[{id, name, unit?}]}]` (id nilai `''` = isian bebas), disaring `kategoriAttributes()`/`saringAtribut()` (hanya bila field dikirim).
  - Service: `kategoriPohon()` (pohon API mentah, cache 1 hari `mp-pohon:{ch}`), `anakKategori()` (telusur bertingkat per level ala Seller Center, rute `marketplace-stock.kategori.anak`), `kategoriDaun()` (daun + jalur "A > B > C"), `cariKategori()`, `atributKategori()` (cache `mp-atribut:{ch}:{id}`; TikTok buang `SALES_PROPERTY`; Shopee `input_type` 1–5 → multi/custom + unit + daftar merek `get_brand_list` s.d. 500), `tarikKategori()` (isi dari listing tertaut: TikTok `getProduct.category_chains/product_attributes`, Shopee `get_item_base_info.category_id/attribute_list/brand`).
  - Payload (`kategoriPayload`, bagian payload konten → ikut diff-guard & sinkron otomatis): TikTok `category_id` + `product_attributes` (SELALU bersama — ganti kategori di TikTok menghapus atribut lama), Shopee `category_id` + `attribute_list` (+ `brand`). Kosong = kategori marketplace tak diubah. Ditahan bila varian lain produk yg sama tertaut ke master lain dgn kategori berbeda (`kategoriBoleh`).
  - Rute (`manage_marketplace_stock`): `marketplace-stock.kategori.cari` / `.kategori.atribut` (JSON; error API → 422 tersamarkan), `marketplace-stock.master.kategori.tarik` (POST, form terpisah di luar form Simpan).
  - ⚠️ Ganti kategori TikTok = produk **direview ulang**. Endpoint & bentuk field dari SDK komunitas (dok resmi tak bisa diakses saat dibangun) — belum diuji ke API asli; gagal → `last_content_status=failed` + pesan asli. Tes: `KategoriMarketplaceTest`.
- Tes: `PushContentTest` (nama 1-SKU, barcode/GTIN, mode otomatis), `PushPhotosTest::test_simpan_sinkron_otomatis_hanya_yang_berubah_setelah_dorong_manual`, `test_manual_paksa_foto_otomatis_lewat_diff_guard`.

### Dorong Konten ke Marketplace (Fase 1)
Kirim **deskripsi / berat / dimensi** master ke listing TikTok & Shopee yang sudah tertaut. **MANUAL** (tombol + `confirm()`), **BUKAN cron** — `marketplace:push-stock` tetap hanya stok+harga; jalur konten sengaja terpisah dari `pushListing`/`pushDirty`/`pushEach`. **Nama/judul/kategori/barcode belum didorong; foto ikut sejak Fase 2 (subseksi "Dorong Foto" di bawah).** Konten adalah **level-item** di kedua marketplace: berlaku untuk seluruh produk listing-nya, bukan per varian.
- **Payload** — `MarketplaceMasterService::buildContentPayload(master, channel)`, **skip-empty**: hanya field terisi yang dikirim (kosong tak menimpa isi marketplace); dimensi hanya bila panjang+lebar+tinggi semuanya > 0; berat gram → kg. TikTok: `description`, `package_weight` (KILOGRAM), `package_dimensions` (CENTIMETER); Shopee: `description`, `weight`, `dimension` (`package_length/width/height`). Tak pernah memuat nama/judul maupun `item_id`.
- **Engine** — `pushContent(listing, master, force): 'ok'|'failed'|'skip'` (`skip` = payload kosong, atau hash sama dgn push sukses terakhir kecuali `force`) dan `pushMasterContent(master, force = true): {pushed, skipped, failed}` (semua listing master ber-`item_id`, dihitung **per listing**). Jejak di kolom `last_content_*` + `content_hash` (migrasi `000151`); gagal → `last_content_status='failed'` + pesan asli (dipotong 500), sementara hash & waktu sukses terakhir **dipertahankan**.
- **Client** — `TikTokClient::partialEditProduct()` (`POST /product/202309/products/{id}/partial_edit`) & `ShopeeClient::updateItem()` (`POST /api/v2/product/update_item`). `ShopeeClient::shopCall()` untuk POST kini menaruh parameter bisnis **hanya di body JSON** (auth/`sign` tetap di query) supaya deskripsi panjang tak membengkakkan URL.
- **Tombol** — kartu "Dorong Konten & Foto ke Marketplace" di form **Ubah** (`<form>` sendiri, di LUAR form Simpan) + item "Dorong konten & foto" di menu **Atur** katalog → `POST /marketplace-stock/master/{master}/konten` (`marketplace-stock.master.konten`, izin `manage_marketplace_stock`, `MarketplaceStockController::pushContent`). Selalu `force` (memang "kirim & timpa"); yang dikirim = data **tersimpan** (Simpan dulu bila baru diubah). Flash jujur: hitungan OK/dilewati/GAGAL (+ "foto diganti di N listing" hanya bila foto benar-benar diganti di klik itu), plus pesan khusus bila belum ada listing tertaut atau tak ada yang dikirim (teks kosong & foto tak ada/tak berubah).
- **Status per listing** — halaman override channel (`/marketplace-stock/{channel}`), kolom **Status kirim**: badge per jenis (`data-kirim`) — `Stok ✓`/`Harga ✓` (hijau, tooltip waktu kirim terakhir), `… gagal` (merah), `… —` (abu, belum pernah dikirim); badge `Konten ✓/gagal` hanya bila listing pernah didorong, tak ikut mewarnai stok/harga. Pesan error di bawahnya: `stok:`/`harga:`/`konten: <pesan>` (dipotong 80 karakter, penuh di tooltip, di-escape). Dulu `ok/ok` tanpa label (dirapikan 2026-10-08, sekalian header Stok/Harga dikelompokkan, foto produk, nama 2 baris, input & tombol simpan ringan, tanda "override" di angka efektif). `last_content_pushed_at` (waktu sukses) belum ditampilkan. Halaman itu hanya menampilkan listing **pertama** per channel untuk tiap master (sama spt status stok/harga).
- **Batas** — sinkron di request web, satu panggilan per listing (master yang tertaut ke banyak SKU dari 1 item mengirim panggilan identik berulang). Tes: `ContentSyncMigrationTest`, `ContentClientTest`, `PushContentTest`, `PushContentActionTest`, `ContentStatusDisplayTest` (di `tests/Feature/MarketplaceMaster/`).

### Dorong Foto ke Marketplace (Fase 2 — digabung ke "Dorong Konten")
Tombol "Dorong Konten" yang sama kini juga mendorong **FOTO** master (koleksi `master_image`, s.d. 9, urut; pertama = cover) — user memilih **digabung**, bukan tombol terpisah. ⚠️ Mendorong foto = **MENGGANTI SELURUH set foto listing** (TikTok & Shopee tak bisa menambah satu-satu).
- **Alur** — upload tiap foto (multipart) lalu set daftar foto: TikTok `POST /product/202309/images/upload` (field `data`, `use_case=MAIN_IMAGE`, tanda tangan dgn body kosong) → `partial_edit` `main_images:[{uri}]`; Shopee `POST /api/v2/media_space/upload_image` (field `image`, tanda tangan level-toko) → `update_item` `image.image_id_list`. `image_id` Shopee dibaca dari `response.image_info` maupun format batch `response.image_info_list[0].image_info`.
- **Pengaman** — master tanpa foto → `skip` tanpa HTTP (foto listing tak dikosongkan). **Diff-guard `photo_hash`** (md5 daftar `[id, sort_order]`): dari tombol, foto **SELALU** lewat diff-guard (`force` hanya berlaku untuk konten), jadi klik ulang untuk memperbarui teks **tak** mengganti/meng-upload ulang foto; set foto berubah (tambah/hapus/ganti foto utama) → dikirim ulang. Satu foto gagal upload → set **tak** dikirim (tanpa ganti parsial); gagal → `last_photo_status='failed'` + pesan asli marketplace, hash & waktu sukses terakhir dipertahankan.
- **Dorong pertama & varian** — ⚠️ setelah deploy semua listing ber-`photo_hash` kosong → **dorong PERTAMA ke tiap listing MENGGANTI SEMUA fotonya** (disebut eksplisit di kartu & konfirmasi; tak bisa dibatalkan dari SKINKU — pastikan foto master lengkap dulu). Foto milik PRODUK sedangkan listing per SKU: tiap sukses set foto mengosongkan `photo_hash` listing sibling (produk sama, hash berbeda — varian yang ditautkan ke master lain) supaya klik terakhir yang menang, bukan skip basi; sibling ber-hash sama (master yang sama) dibiarkan.
- **Engine** — `MarketplaceMasterService::photoHash(master)` & `pushPhotos(listing, master, force, &$uploaded)`; `pushMasterContent` menjalankan konten **dan** foto per listing dengan status gabungan (`failed` bila salah satu gagal; `pushed` bila salah satu terkirim; selain itu `skipped`). **Anti-timeout**: upload sekali per channel per run (cache `$uploaded` berkunci `channel:hash`, tak pernah terisi parsial) + `set_time_limit(180)` best-effort di controller (dilewati bila fungsi dinonaktifkan hosting). Nama file upload aman `foto-{id}.{ext}` (bukan nama asli — tanda kutip merusak header multipart).
- **Client** — `TikTokClient::uploadImage()` & `ShopeeClient::uploadImage()` (multipart; helper JSON `request()`/`shopCall()` tak dipakai). `ShopeeClient::handle()` kini juga melempar untuk HTTP non-2xx tanpa field `error` (mis. 502 halaman HTML gateway) — sebelumnya tercatat sukses kosong. Pesan error yang disimpan (`last_error`/`last_price_error`/`last_content_error`/`last_photo_error`) menyamarkan `access_token`/`sign`/`refresh_token`/dll. (saat timeout Guzzle menempelkan URL lengkap berikut query).
- **Data & tampilan** — migrasi **`000153`** (nomor `000152` sudah dipakai sesi lain): `last_photo_status`/`last_photo_error`/`last_photo_pushed_at`/`photo_hash` di `marketplace_listings`. Halaman channel menampilkan badge `Foto ✓` (hijau) / `Foto gagal` (merah) + `foto: <pesan>` (dipotong 80, penuh di tooltip) sejajar badge Konten.
- **Belum pasti** — dokumentasi publik TikTok tak memastikan `partial_edit` menerima `main_images`; bila TikTok menolak, tercatat `failed` + pesan asli TikTok (tak diam) — cek saat smoke-test deploy. Tes: `PhotoSyncMigrationTest`, `PhotoUploadClientTest`, `PushPhotosTest`, `PhotoStatusDisplayTest` (di `tests/Feature/MarketplaceMaster/`).

### Modal "Kaitkan Produk" (ala Desty)
Ganti picker `<select>` lama. Dipicu dari dua tempat pada `index.blade.php`: tombol Atur → "Tambah ke Marketplace" (tab default **Semua**), atau klik angka **Produk Terkait**/**Toko Terkait** (tab default **Produk Terkait**) — keduanya lewat `mpOpenKaitkan(this)` yang baca `data-master-id`/`data-master-sku`/`data-tab` dari tombolnya.
- **Tab**: Semua · Produk Terkait · Produk Tidak Terkait — tiap tab tampilkan hitungan live (`kaitkanCountAll`/`Terkait`/`Tidak`).
- **Search** (nama/SKU) + **filter channel** (Semua/TikTok/Shopee) — murni client-side, tak ada request server.
- **Baris listing**: checkbox + Informasi Produk + SKU Marketplace + Channel/Toko (pakai `shopNames` utk nama toko) + badge status — ✅ Tertaut (ke master ini), ⬜ Belum (master lain manapun), atau 🔗 *nama master lain* (checkbox **disabled**, tak bisa dipilih dari sini — cegah rebut listing lewat modal, harus lepas dulu dari master asalnya).
- **Bulk**: **"Tautkan terpilih"** → POST `marketplace-stock.kaitkan` (hanya checkbox berstatus "Belum" yang tercentang); **"Lepas terpilih"** → POST `marketplace-stock.lepas` (hanya checkbox berstatus "Tertaut"). Masing-masing lewat hidden `<form>` (`listing_ids[]`), `action` di-substitusi JS dari template URL berplaceholder `__ID__` (`kaitkanTpl`/`lepasTpl`) karena `route()` di-render sekali per page-load, bukan per master.
- **Data**: seluruh listing (`allListings`: id/channel/seller_sku/title/master_id) + `masterNames`/`shopNames` di-embed **sekali** ke halaman lewat `window.__mp = {...}` pakai `json_encode($x, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)` di dalam `<script>` (bukan literal `@json([...])`). Semua render/filter modal murni JS vanilla inline (`mpOpenKaitkan`/`mpCloseKaitkan`/`mpSetKaitkanTab`/`mpRenderKaitkan`/`mpSubmitKaitkan`), tanpa AJAX/lib tambahan.

### Alur
1. **Refresh Listing** (`resolve`) — isi/segarkan cache `marketplace_listings` dari TikTok+Shopee (murni metadata, TANPA bikin/tautkan master).
2. **Tambah Produk Baru** (`create`/`store`) — admin isi nama/SKU/harga/stok/tipe/kategori/deskripsi/foto (s.d. 9)/berat/dimensi/barcode master secara manual (form lengkap, lihat subseksi di atas). Di Ubah, foto lama bisa dihapus / dijadikan utama lewat galeri (`master.foto.hapus`/`master.foto.utama`).
3. **Kaitkan Produk** — dari menu Atur (atau klik angka Produk/Toko Terkait) → modal "Kaitkan Produk": cari/filter listing, centang lalu **"Tautkan terpilih"** (`kaitkan`, bulk) → stok/harga master mulai disinkron ke channel listing itu; **"Lepas terpilih"** (`lepas`, bulk) untuk memutus tautan kapan pun diperlukan.
4. **Rapikan katalog** — Ubah / Duplikat / Jadikan Bundle-Satuan / Hapus lewat menu Atur kapan pun diperlukan.
5. **Override per channel** (`/marketplace-stock/{channel}`) — admin isi stok/harga khusus 1 channel bila perlu beda dari Master, **langsung di tabel**: ketik + Enter atau tombol simpan (utk HP — Enter sulit; cegah kirim dobel blur+klik) → tersimpan tanpa reload (`POST marketplace-stock.override`, JSON, validasi manual 422) & langsung didorong (`pushMaster`), kursor lompat ke produk berikutnya; kosongkan = ikut Master. Respons membawa angka efektif + badge status kirim terbaru (partial `_status-kirim`); audit `update_marketplace_override`. Test: `OverrideOtomatisTest`.
6. **Push berkala** — cron `marketplace:push-stock` tiap 5 menit → `pushDirty()` (hanya listing yang nilainya berubah sejak push terakhir; §20); tombol "Sinkron semua" (`push-all`) dorong paksa (`force=true`) kapan saja.
7. **Order masuk** — `TikTokOrderService`/`ShopeeOrderService` potong stok HQ (jalur lama, tak berubah) **dan** panggil `applyOrderDelta()` supaya bucket master/override ikut turun — HQ tak disentuh oleh jalur ini.
8. **Dorong Konten (opsional, manual)** — di form Ubah / menu Atur, tombol "Dorong Konten" mengirim deskripsi/berat/dimensi tersimpan **+ foto (hanya bila set foto berubah; mengganti seluruh foto listing)** ke listing tertaut (tak ikut cron); hasilnya tampil per listing di halaman override channel (badge `Konten ✓/gagal`, `Foto ✓/gagal`).
9. **Naikkan Produk Shopee otomatis** (ala Desty, 2026-10-08; migrasi `000161` tabel `shopee_boost_items`) — tombol "Naikkan Produk" di halaman Stok & Harga Shopee → `/marketplace-stock/shopee/naikkan` (`ShopeeNaikkanProdukController`, izin `manage_marketplace_stock`): saklar ON/OFF (`AppSetting` `shopee_naikkan_aktif`), pilih **maks 5 produk** (batas Shopee; per item, varian digabung; dari listing Shopee), status per produk (sedang naik + sisa waktu / menunggu / gagal + alasan Shopee), tombol "Jalankan sekarang" (jalan walau saklar mati). Putaran `ShopeeBoostService::jalankan()` via command `shopee:naikkan-produk` **tiap 10 menit, 24 jam**: `get_boosted_list` (SEMUA item toko yg masih naik + `cool_down_second` — slot 5 dibagi satu toko, termasuk yg dinaikkan Desty / manual) → `boost_item` hanya sebanyak slot kosong, **satu item per panggilan** (melebihi batas = seluruh permintaan ditolak "reached shop's bump slot limit"; satu per satu → yang muat tetap naik, berhenti saat Shopee bilang penuh — aman walau batas toko sebenarnya < 5); sisanya `last_status=penuh` + perkiraan kapan slot kosong; produk lain yg memakai slot disimpan di `AppSetting` `shopee_naikkan_slot` & ditampilkan di halaman (saran matikan Naikkan Produk di Desty). Hasil `success_list`/`failure_list` dicatat per produk; gagal total (token/izin/jaringan) → `last_error`; label belum jalan = "Menunggu putaran HH.MM". Foto produk = foto asli Shopee (`get_item_base_info`, diambil saat putaran utk produk pilihan + semua listing Shopee yang belum ada fotonya, disimpan di cache `shopee_products` — dipakai juga Chat E-commerce lewat `ShopeeProduct::simpanDariBaseInfo`), fallback foto master. Tambah produk lewat kotak cari nama / SKU / item id (saring tanpa reload). Audit `shopee_naikkan_aktif/tambah/hapus/jalankan`. Test: `ShopeeNaikkanProdukTest`.

### Route / izin
`MarketplaceStockController` (nama kelas dipertahankan dari Fase 1 demi kestabilan URL `/marketplace-stock*`) — rute `index`/`create`/`store`/`edit`/`update`/`duplikat`/`channel`/`master.stok`/`master.harga`/`channel.stok`/`channel.harga`/`ikut-master`/`tautkan`/`kaitkan`/`lepas`/`push-all`/`resolve`/`master.hapus` (`DELETE`)/`master.bundle`/`master.konten` (`POST /marketplace-stock/master/{master}/konten` — Dorong Konten, lihat subseksi di atas)/`master.foto.hapus` (`DELETE /marketplace-stock/master/{master}/foto/{file}`)/`master.foto.utama` (`POST /marketplace-stock/master/{master}/foto/{file}/utama`) — dua rute foto terakhir = pengelolaan foto per-file dari galeri form Ubah (guard IDOR `assertFotoMilikMaster`, lihat subseksi form). `kaitkan`/`lepas` (masing-masing `POST /marketplace-stock/master/{master}/kaitkan|lepas`) = bulk link/unlink dari modal "Kaitkan Produk" (lihat atas); `tautkan` (single, legacy) tetap ada & dites (`TautkanTest`) tapi **tak lagi punya entry point UI** sejak modal ini ada. **Dihapus** (model auto sebelumnya): `siapkan`, `seed-tiktok`, `masterize-all`, `master.gabung`, push per-master (`push/{master}`), `master.foto` (rute upload-1-foto terpisah; BUKAN `master.foto.hapus`/`master.foto.utama` yang baru) — upload foto sekarang ditangani langsung di form `create`/`update` (`enctype="multipart/form-data"`, `foto[]` banyak sekaligus). View katalog pakai POST + `@csrf` biasa; data modal di-embed via `json_encode(..., JSON_HEX_TAG|...)` dalam `<script>` (bukan literal `@json([...])`).

**Izin:** `manage_marketplace_stock` ("Kontrol Stok Marketplace" di matriks `/permissions`, default admin; super_admin implisit) — sama utk `kaitkan`/`lepas` dan rute foto (`master.foto.hapus`/`master.foto.utama`); non-izin → 403.

---

## 10. Akuntansi / GL (buku besar)

**Tujuan:** buku besar double-entry in-house (tanpa paket) yang mendasari kedua integrasi marketplace + pembukuan manual/impor. Satu choke point (`AccountingService::record()`) memaksa balance; semua laporan (neraca saldo, laba rugi, neraca, arus kas) diturunkan dari agregasi baris jurnal ter-posting.

### Model & tabel
| Model | Tabel | Catatan |
|---|---|---|
| `AccBranch` | `acc_branches` | Cabang = **dimensi**, bukan ledger terpisah (seed `SBY-T`). |
| `AccAccount` | `acc_accounts` | `code` (unik), `type` (asset/liability/equity/revenue/expense), `subtype`, `normal_balance`, `legacy_code`. |
| `AccJournal` | `acc_journals` | `branch_id`, `date`, `period` (`YYYY-MM` auto), `reference`, `type` (general/sales/purchase/cash_in/cash_out/inventory/adjustment), `status` (draft/posted/void), `source_type`/`source_id`, `import_hash` (dedup, `000026`). |
| `AccJournalLine` | `acc_journal_lines` | `journal_id`, `account_id`, `branch_id` (per-baris, boleh split lintas-cabang), `debit`/`credit`, `memo`. |
| `AccTemplate` / `AccTemplateLine` | `acc_templates` | Preset jurnal untuk form entri manual. |

Migrasi dasar `000000`; COA di-seed `ChartOfAccountSeeder` (upsert idempotent by `code`).

### Bagan Akun (COA) — ringkas
- **Aset:** 1001 Kas Shopee · 1002 Bank · 1003 Kas TikTok · 1101 Piutang Usaha · 1201 Persediaan Bahan Baku · 1202 Persediaan Barang Jadi · 1301-1305 uang muka · 1401-1402 aset tetap · 1501-1502 akum. penyusutan.
- **Liabilitas:** 2001-2008 (hutang usaha/gaji/pajak/iklan/deposit/lain/pendapatan diterima dimuka) · 2101 Hutang Bank.
- **Ekuitas:** 3001 Modal · 3002 Prive · 3003 Ikhtisar L/R.
- **Pendapatan:** 4001 Penjualan · 4002 Pendapatan Lain-lain · 4003 Bunga · 4004 Ongkir · 4101 Retur Penjualan (kontra) · 4102 Potongan Penjualan (kontra).
- **HPP:** 5001 Pembelian · 5002 Retur Pembelian (kontra) · 5003 Beban HPP · 5004 Gaji Produksi.
- **Beban operasional:** 6001-6013 (Iklan · Gaji · Sewa · Administrasi · **6005 Biaya E-commerce** · Listrik/Air · **6007 Ongkos Kirim** · Operasional · Perlengkapan · Penyusutan · Sample · Lain-lain).
- **Non-operasional:** 7001 Beban Bunga · 7002 Beban Pajak.
- ⚠️ Akun kontrol **1103 (Piutang TikTok), 1104 (Piutang Shopee), 1203 (Persediaan Dalam Perjalanan)** TIDAK di seeder — dibuat on-demand via `firstOrCreate()` saat jurnal marketplace pertama diposting.

### Service
- **`AccountingService`** — mesin posting, **satu-satunya pintu** buat jurnal.
  - `record(header, lines, status=POSTED)` — validasi: tak ada debit/credit negatif, tak ada baris debit-DAN-kredit sekaligus, skip baris nol, wajib `account_id`, wajib ≥2 baris, wajib `total_debit == total_credit` (toleransi ±0.005), turunkan `period`. Throw `AccountingException` kalau langgar. Dibungkus `DB::transaction`.
  - `post()` (draft→posted, re-validasi balance) · `void()` (tandai void, berhenti dihitung; tak butuh jurnal balik) · `balanceOf(accountId, period)`.
- **`LedgerService`** — sisi-baca, semua difilter `status = posted`.
  - `accountNets()` — query dasar tiap laporan: per-akun SUM(debit)/SUM(credit)/net.
  - `trialBalance()` (neraca saldo kumulatif) · `generalLedger()` (buku besar per-akun: saldo awal + entri + running balance).
- Laporan turunan: **`FinancialReportService`** (`incomeStatement` Laba Rugi, `balanceSheet` Neraca dengan "laba berjalan" YTD), **`CashFlowService`** (`directCashFlow` metode langsung, kategori Operasi/Investasi/Pendanaan dari tipe counter-account), **`ComparativeReportService`** (`summary`/`monthlyReport` untuk banding/tren).

### Alur double-entry
1. Tiap peristiwa keuangan (tahap TikTok/Shopee, entri manual, impor Excel, impor mutasi bank) akhirnya memanggil `AccountingService::record()`.
2. `record()` satu-satunya tempat membuat `AccJournal`+`AccJournalLine`; self-validasi balance.
3. Laporan tak pernah sentuh data mentah — hanya agregasi baris jurnal `posted` via `LedgerService`. `source_type` (`tiktok_order_transit`, `shopee_settlement`, `shopee_wallet`, `excel_import`, `opening_balance`, `bank_import`, dst.) murni untuk audit + target `unpostAll()`.
4. Void = flip status (non-destruktif). Hard-delete (`journalDestroy`) permanen, digating izin terpisah `delete_accounting`.
5. Dedup impor: `import_hash` (SHA1 branch+date+reference+tanda-tangan baris + counter kemunculan) untuk importer Excel & mutasi bank.

### Route / izin
`AccountingController` (517 baris), semua di bawah `permission:view_accounting`:
- **Laporan:** `/accounting/laporan` (gabungan), `/laba-rugi`, `/neraca`, `/arus-kas`, `/banding`, `/tren`, `/neraca-saldo` — **juga butuh `view_hpp`** (sejak 2026-10-08: laporan saling terkait, laba/persediaan cukup utk menghitung HPP). Tanpa izin: tab laporan disembunyikan (`accounting/_nav`), `/accounting` langsung ke Jurnal; input/impor jurnal, COA & template tetap cukup `view_accounting`.
- **Entri manual:** `/accounting/jurnal` (list), `/jurnal/baru` (form + preset template), `POST /jurnal`, `POST /jurnal/{j}/void`.
- **Hapus** (gate ketat): `DELETE /accounting/jurnal/{j}` butuh `delete_accounting` (default **kosong** — hanya super_admin).
- **Impor Excel:** `/accounting/impor-excel` (klien parse → `journals[]`, server validasi balance + dedup; flag `is_opening` tag `opening_balance`).
- **Impor mutasi bank:** `/accounting/impor` (1 jurnal per baris bank vs akun bank terpilih; `keluar` → Dr counter/Cr bank, `masuk` → Dr bank/Cr counter).
- **COA / Template:** `AccAccountController` (`/accounting/coa*`), `AccTemplateController` (`/accounting/template*`); destroy digating `delete_accounting`.

**Izin:** `view_accounting` (default admin; jurnal/COA/impor), `view_hpp` (default kosong = super admin; laporan keuangan), `delete_accounting` (default **nobody** — komentar "hapus jurnal itu permanen").

---

## 11. Dashboard & Laporan

### `ReportService` — semua metode publik
Docblock: *"Semua laporan berbasis agregat SQL — tak pernah mock. 'Sales' = PO completed saja."*

| Metode | Fungsi |
|---|---|
| `summary(?User, ?month, allChannels)` | Bundel KPI utama. `allChannels` = ikut TikTok/Shopee (Dashboard); PO-only (Laporan Penjualan). |
| `downlineSalesReport(seller, ?month)` | Laporan Model-A: penjualan 1 mitra-stockist ke downline (net retur), per-buyer & per-produk. |
| `channelSales(?month)` | Penjualan 1 bulan per channel (Reseller/PO, TikTok, Shopee) × confirmed/pipeline/cancelled/unpaid + cancel rate. |
| `yearlyOmzet(?year)` | **Grand-total omzet setahun** semua channel: realized + pipeline + total. |
| `grossProfit(?month)` | Estimasi laba kotor PO completed pakai COGS rata-rata kini. |
| `salesTrend(granularity, points, ?User, ?month)` | Total sales per bucket waktu (hari/minggu/bulan); zero-fill hari saat 1 bulan dipilih. |
| `salesByProduct(limit, ?User, ?month)` | Produk teratas by revenue. |
| `ProdukTerlarisService::report(month)` *(service terpisah)* | Panel **Produk Terlaris** dashboard (staff): unit terjual per channel (Semua/Reseller-PO/TikTok/Shopee), order berbayar (selesai+berjalan), SKU marketplace di-resolve lewat peta SKU/bundle (isi × qty), SKU belum dipetakan tetap tampil bertanda; ▲▼ vs bulan lalu. Ikut `?bulan` dashboard. |
| `BusinessReportService` *(menu Laporan → Generate Report, `/laporan-bisnis`)* | Laporan Mingguan/Bulanan/Kuartal/Tahunan/Custom vs periode sebelumnya, plus **Semua Periode** (sejak transaksi pertama, tanpa pembanding; laju stok pakai 90 hari terakhir) (periode berjalan dipotong s/d hari ini). Merangkai channelSales, ProdukTerlaris, mitra/PO (top, tidak order, retur), stok vs laju jual, KOL (izin `kol.affiliate.view`), laba rugi (izin `view_accounting` + `view_hpp`; ikut tertutup di Excel & analisis AI). PDF = print browser (grafik Chart.js), Excel = `XlsxWriter` multi-sheet, analisis AI via `ReportAi::analyze` (AJAX, cache 12 jam, audit `generate_business_report_ai`). Staff + `view_reports`. |
| `partnerSalesDetail(?month)` | Detail penjualan per-mitra (grup `company_name`). |
| `salesByPartner(role, limit, ?month)` | Penjualan per-mitra dalam 1 role. HQ-only. |
| `omzetPerMitra(?month)` | Gabung 2 jalur sebagai seller: PO ke downline + `PartnerSale` ke end-customer. |
| `salesByRegion(?month)` | Penjualan per `region` (fallback "Lainnya"). |
| `poStatusDistribution(?User, ?month)` | Hitung PO per status (pie). |
| `inventoryMonitoring(limit)` | Stok HQ vs stok mitra per produk (bar). |

Helper privat: `inMonth()` (scope `order_date ?? created_at`), `scopePo()` (mitra lihat PO sendiri; view HQ kecualikan PO antar-mitra), `downlineSalesNet()` (PO − retur), `dateFormatExpr()` (bucket per driver mysql/pgsql/sqlite).

### Dashboard (`DashboardController::index` → `/dashboard`)
Pengumuman role-scoped (note-box + popup sekali/hari), filter `?bulan=YYYY-MM`, `summary(allChannels:true)`, `poStatusDistribution`, trend 31 hari, **staff-only** `channelSales` + `yearlyOmzet` (2 kotak omzet: Grand Total tahunan + Omzet Distributor/PO realized-vs-pending — commit `56d2a6a`), recent PO, low-stock, panel "Perlu Tindakan" (withdrawal pending, gate `process_withdrawal`). Role izin-terbatas (bukan staf, bukan mitra) dapat dashboard shortcut minimal.

### Menu Laporan (`ReportController`)
- `/reports` — **Laporan Penjualan** (izin `view_reports`): summary, trend, top produk, status PO, inventory; ekstra HQ-only (laba kotor, detail mitra, sales-by-distributor, sales-by-region).
- `/reports/penjualan-downline` — **Penjualan Downline** (mitra stockist saja).
- `/reports/omzet-mitra` — **Omzet Mitra** (staff-only) + grand total.
- `/reports/komisi[..]` — **Laporan Komisi** (izin terpisah `view_commission_report`) → delegasi `CommissionService`.
- `/reports/chart-data` — endpoint JSON untuk widget chart.

### Laporan Stok HQ
`/laporan-stok-hq` (izin `manage_hq_stock`) → `HqStockReportService` (lihat [§7](#7-inventory--stok)). Di sidebar grup **Stok**, bareng Stok Opname + Penjualan Back-date.

**Izin:** `view_reports`, `view_commission_report`, `manage_hq_stock`.

---

## 12. OKR (AI-drafted)

**Tujuan:** OKR di-draft AI (3 panel spesialis — **CMO/CFO/COO** — direkonsiliasi orchestrator) yang, setelah **disetujui manusia**, terwujud jadi kartu Kanban riil. AI **hanya mengusulkan**; semua mutasi terjadi setelah persetujuan eksplisit.

### Model (`app/Models/Okr*.php`)
- **OkrCycle** — periode (bulanan/kuartalan), scope (company/team/individual), `status` (draft/active), `generation_status` async (generating/ready/failed), field analisis (JSON: summary/evidence/assumptions/conflicts/data_coverage).
- **OkrObjective** — milik cycle; `specialist` (cmo/cfo/coo), title/rationale/owner.
- **OkrKeyResult** — milik objective; metric/target, `baseline_status` (actual/assumption/needs_validation) + baseline/source, `target_gap`.
- **OkrTask** — milik KR; assignee, `board_column_id` + `board_card_id` (tautan ke Kanban).

### `OkrAiService`
- `generate/startGeneration/runGeneration` — buat cycle placeholder instan (request web tak blok), lalu **job background** (`GenerateOkrDraftJob`) kerja berat: 3 panel spesialis dipanggil **paralel** via `ConcurrentAiProvider::chatMany()`, masing-masing di-ground data `OkrBusinessSnapshotService`, lalu **orchestrator** rekonsiliasi jadi 1 draft (structured-output schema `susun_draf_okr`).
- Tiap klaim angka wajib mengutip `source_path` dari katalog bukti server (server fetch ulang nilai riil — model tak bisa mengarang); draft ungrounded ditolak server (`AiException`).
- `approve(cycle, user)` — atomik (transaksi, row-locked) buat 1 kartu Kanban per task, tautkan `board_card_id`, flip cycle `active`. Gate validasi `approvalIssues()` (owner harus internal aktif, baseline harus dibenarkan, task tak boleh mulai di kolom Done, dst.).

### `OkrBusinessSnapshotService`
Snapshot read-only **difilter izin** (`Permissions::roleHas`): CMO → data sales/channel/funnel/produk; CFO → laba-rugi/neraca/cashflow + baseline "bulan tutup terakhir"; COO → stok/produksi. Bangun `evidenceCatalog()` (fakta scalar → source_path) + `coreFacts()`. Pisahkan KOL (endorsement) dari "affiliate" (`source_not_available` — belum wired).

### Route / izin
`okr.index/show/status` (izin `okr.view`); `okr.create/generate/update/approve/destroy` (izin `okr.manage`). `update()` biar manusia koreksi draft pra-approve (guard IDOR, whitelist member/kolom, cek tanggal-in-period). `destroy()` bisa hapus OKR **draf MAUPUN aktif**: transaksional — `forceDelete()` kartu Kanban (`BoardCard`, pakai SoftDeletes; komentar ikut cascade) dari semua tugas cycle dulu, lalu `$okr->delete()` cascade objectives→KR→tasks. Tombol Hapus ada di kartu daftar `/okr` (form sibling anchor) & halaman detail (label/konfirmasi adaptif draf vs aktif). **Hard-block `internal`** (mitra tak pernah lihat OKR). Sidebar: akordeon **"Produktivitas"** (OKR + Kanban + Mindmaps).

---

## 13. Kanban

Infrastruktur load-bearing untuk **OKR** (task approved → kartu) & **AI Assistant** (tool `buat_kartu_kanban`).

- **`Board`** (soft-deletes; kolom default To Do/Proses/Selesai) + **`BoardColumn`** + **`BoardCard`** (migrasi `000050`, `000061` created_via, `000062` completed_at). Komentar kartu `000051`.
- `KanbanKpiService` — KPI per-orang (total/done/late/on-time/score%).
- **Izin `kanban.view`**, hard-block `internal` (mitra diblok). Sidebar grup **Produktivitas**.

---

## 14. Mindmaps

**Tujuan:** kanvas sticky-note/diagram multi-user ala Miro, umum (bukan khusus OKR).

- **`Mindmap`** (`title`, `created_by`; `isOwner/canView/canEdit`) + **`MindmapNode`** (type sticky/text, x/y/w/h, text, color) + **`MindmapEdge`** (from/to/label) + **`MindmapMember`** (pivot `can_edit`). Migrasi `000071` (cascade delete node→edge, mindmap→semua).
- **`MindmapController`** — CRUD board, membership (owner-only), **API kanvas JSON**: `state()` (poll), `storeNode/updateNode/destroyNode`, `storeEdge/updateEdge/destroyEdge` — **tiap elemen = 1 baris/request** (auto-save per elemen, bukan simpan seluruh kanvas) supaya editor konkuren tak saling timpa.
- **Izin `mindmap.view` + `internal`**. Akses per-board dicek di controller. Sidebar grup **Produktivitas**.

---

## 15. KOL (endorsement)

**Tujuan:** memindahkan workflow kurasi KOL berbasis Excel (screening/skor CPM → deal endorse berbayar → laporan hasil) jadi 3 tahap tertaut.

### Model
- **`Kol`** — master (username/platform/followers/kategori/agency/phone, soft-delete). `level` = **accessor** turunan dari followers (Nano <10k … Super Mega >2.5M), **tak disimpan** → ubah config tak bikin desync. Helper `profileUrl/whatsappUrl`.
- **`KolScreening`** — 1 event screening (ratecard + 7 view-count video). Semua angka turunan = **accessor** (median/mean views, CPM, ratio, estimasi GMV, label viral/fake-follower, verdict) → ubah threshold tak tinggalkan verdict basi. Verdict: 🟢 Worth It / 🟡 Masih Oke / 🔴 Kemahalan / ⚪ Belum Ada Ratecard (basis CPM-median, threshold di `config/kol.php`).
- **`KolDeal`** — kontrak endorse berbayar (jenis vt/live, slot, periode, PIC, MOU, status draft/berjalan/selesai/batal + laporan "hasil" pasca-campaign → CPM/ROMI/verdict accessor). `FINANCE_FIELDS` const memusatkan field sensitif (strip input tak-berwenang + jauhkan dari audit log).

### Service
- **`KolService::upsertScreening()`** — transaksi "find-or-create KOL by username (case/@-insensitive) + tambah screening", dipakai identik oleh form manual & importer massal.
- **`KolImportService`** — impor massal 2-fase (`.xlsx`/`.csv`): `preview()` (validasi/dedup/klasifikasi, no DB) → `commit()`. Dedup = username + tanggal_listing (aman re-upload).

### Controller
`KolController` (database KOL sortable/filterable), `KolScreeningController` (form 1-submit register-or-reuse + screening), `KolImportController` (template + preview/commit), `KolDealController` (CRUD deal, `bulkStatus()`, `saveHasil()` modal AJAX, `laporan()` dashboard hasil peringkat). **Pemisahan approval:** hanya `kol.deal.approve` boleh gerak deal ke `berjalan`/`batal`; submitter (`kol.deal.manage`) boleh draft/selesai.

**Izin:** `kol.view`, `kol.screening.manage`, `kol.deal.manage`, `kol.deal.approve` (approver ≠ submitter), `kol.deal.finance` (field uang), `kol.report.view` (reserved). Role default: custom `kol_specialist` (+ admin untuk deal.manage/approve).

### Sub-menu KOL Fase 1 (grup accordion — pipeline, reminder, konten)

Menu KOL = **grup accordion**: Database KOL · Pipeline · Konten & Views · Reminder · Deal KOL. Semua menempel ke tabel `kols` (bukan tabel creator baru). Diadaptasi dari app lokal "KOL Command Center" (repo terpisah `Iyuro/skinku`, Next.js); scraper TIDAK ikut (agen lokal fase depan — kolom `source` snapshot sudah disiapkan). Spec: `docs/superpowers/specs/2026-08-27-kol-submenu-fase1-design.md`.

- **Pipeline scouting** (`KolPipelineController`, `kol_pipeline_cards` + `kol_pipeline_events`, migrasi 000099) — kanban 9 stage (kandidat→…→drop), **1 kartu aktif per KOL** (unique kol_id+track), next-action+tanggal, followup_count; tiap pindah stage tulis event **append-only**. Hapus kartu = super_admin (jalur normal = geser ke Drop). Izin tulis `kol.pipeline.manage`.
- **Tim Gapok** (`KolGapokController` + `KolGapokService`) — gaji pokok bulanan & cicilan pembayaran per bulan gaji; bayar telat = buka bulan gajinya, tanggal bayar terpisah (`paid_at`). Simpan gaji / tanggal gabung / bayar / hapus bayar tercatat di Audit Log (`set_gapok_salary` sebelum→sesudah, `set_gapok_join_date`, `add_gapok_payment`, `delete_gapok_payment` + bulan & tanggal bayar); catatan gaji tak ikut dicatat. Kolom **Video & LIVE** = video yang **diunggah** & LIVE yang **dimulai** di rentang terpilih (bulan/preset/custom) — `KolGapokService::jumlahKonten()` (COUNT DISTINCT content_id lintas potret bulanan `kol_creator_contents`, sama dgn "Diposting" Views Harian; video lama yang masih laku tak dihitung, penjualannya tetap masuk GMV). Daftar konten (`kol-gapok.contents`, `KolGapokService::konten()`) ikut rentang yang sama; views/GMV/order = total semua potret. `kol_creator_content_stats` (hitungan per bulan) masih ditulis sync tapi tak dipakai tampilan lagi (2026-10-08). ⚠ Bulan `YYYY-MM` selalu dibaca `Carbon::createFromFormat('!Y-m', …)` — tanpa `!` tanggal diisi dari hari ini sehingga di tanggal 29–31 bulan 30 hari/Februari meluap ke bulan berikutnya (dijaga `tests/Feature/BulanTanggal31Test.php`).
- **Reminder** (`KolReminderController` → `KolReminderService::untuk()`, dipakai juga alat AI `reminder_kol`; baca-saja) — agregat pipeline: terlambat → jatuh tempo hari ini → tanpa next-action (drop dikecualikan).
- **Konten & Views** (`KolContentController`, `kol_contents` + `kol_content_snapshots`, migrasi 000100) — arsip konten per KOL + **snapshot views bertanggal append-only** (unique kol_content_id+captured_on → isi ulang hari sama = replace). **Anti-dobel-hitung:** konten ber-`kol_deal_id` DIPAKSA `paid`, sisanya `earned`. Ringkasan bulanan (total/paid/earned + proyeksi pace vs `kol_views_target` AppSetting) di `KolKontenService::bulan()` — dipakai juga alat AI `konten_views_kol`. **Grid isi views massal** (spreadsheet-like, snapshot hari ini). Autofill judul via **TikTok oEmbed** (host allowlist tiktok.com). Izin tulis `kol.content.manage`. ⚠ Lookup `captured_on` pakai Carbon `startOfDay()` (string Y-m-d meleset dari nilai datetime tersimpan).

**Izin baru:** `kol.pipeline.manage`, `kol.content.manage` (default `kol_specialist`).

### Sub-menu KOL Fase 2 — Deal & Budget

`KolBudgetService` di atas `kol_deals` (tanpa tabel/izin baru): panel budget bulanan di halaman Deal (finance-gated) — **spent** (deal lunas) / **committed** (aktif belum lunas) / **sisa** (dari cap `kol_budget_monthly`); **blended CPM paid** = biaya deal ÷ views konten paid (Fase 1) vs `kol_cpm_anchor`; warning 1-KOL>40%. Reminder tagihan deal belum lunas ikut di halaman Reminder.

### Sub-menu KOL Fase 3 — Affiliate & GMV · Skor · Agen (migrasi 000101)

Rumus di-port PERSIS dari app lokal `Iyuro/skinku`. Spec: `docs/superpowers/specs/2026-08-27-kol-fase3-*`.
- **3a Affiliate & GMV** — `kol_affiliate_transactions` (unique platform+order_id, kol_id null=belum cocok) + `KolAffiliateService` (import dedup/match, ranking bulanan kecuali batal, weeklyGmv, unmatched, apsInput). Halaman ranking GMV/komisi/order + layar **Belum Cocok** (tautkan username→KOL). **Import XLSX/CSV** auto-map header (reuse `SpreadsheetReader`). Izin `kol.affiliate.view` (angka uang) + `kol.affiliate.manage` (import/cocok).
- **3b Skor** — `App\Support\KolMetrics` (cpm/ecpm/rpm/roas/growthVelocity/consistency/median/pace) + `KolScoringService` (**APS** 4-mingguan: growth 35%+RPM 25%+konsistensi 20%+skala 20%, reweight bila views null, cap 40 bila 2mgg no-post, label ≥75 bina/≥50 pantau/<50 nurture · **KSS** kalkulator seleksi: eCPM 35%+ER 20%+niche 20%+riwayat 15%+kesiapan 10%, ≥70 shortlist/≥50 nego/<50 tolak). KSS di menu **Skor**; APS jadi kolom di ranking affiliate.
- **3c Agen** — endpoint `POST /api/kol-agent/affiliate` (header `X-Agent-Token`, CSRF-exclude, source=agent) untuk app lokal setor transaksi hasil scrape. Token `KOL_AGENT_TOKEN`.

**Izin baru:** `kol.affiliate.view`, `kol.affiliate.manage`.

### Report Views Harian video SKINKU (migrasi `000158`)
- **Potret harian** tiap video SKINKU ke `kol_content_daily_snapshots` (kumulatif bulan per video s/d kemarin; idempoten per hari) diambil **12:30** oleh `tiktok:affiliate-views-backfill --terakhir=2 --koreksi --simpan` (potret hari ini + koreksi 2 hari sebelumnya, angka hanya naik). Dulu ditulis `tiktok:affiliate-content-sync` 04:00 (`end_date_lt` akhir bulan) — terlalu pagi, data TikTok kemarin belum lengkap → potret 3 Okt sama dgn 2 Okt: tgl 2 Okt kosong & views-nya menumpuk di 3 Okt. Sync 04:00 kini hanya data bulanan (`kol_creator_contents`). Perbaikan sekali: `--koreksi --dari=2026-10-02 --simpan`.
- `KolViewsHarianService::report()` — views tanggal D = potret (D+1) − potret sebelumnya; ganti bulan = kumulatif sejak tgl 1; video tanpa potret sebelumnya dihitung penuh hanya bila baru diposting (selain itu titik awal); selisih negatif → 0.
- Halaman **Views Harian SKINKU** (`kol-views-harian.index`, izin `kol.affiliate.view`): kreator × tanggal (default 14 hari s/d kemarin, maks 62), heatmap, total, GMV, cari kreator + Export Excel. Kolom **Diposting** = jumlah video SKINKU yang diunggah di rentang (`posted_at` TikTok, dari potret mana pun — termasuk yang views-nya baru datang setelah rentang; kreator seperti itu tetap tampil dgn views 0). Video lama yang masih ditonton tak dihitung (views-nya tetap masuk). Kolom "jumlah video yang dapat views" sengaja dihapus (membingungkan). **Klik nama kreator** → `kol-views-harian.show` (`/kol-views-harian/{kol}?dari=&sampai=`): daftar video kreator itu — views harian per video (heatmap), tanggal posting, tanda **diupload di rentang ini** (jumlahnya = Diposting), GMV, judul → link TikTok. `KolViewsHarianService::videos()` memakai perhitungan per video yang sama dgn `report()` (`perVideo()`), dan cek "kemarin sudah dipotret" selalu dari potret SEMUA kreator → total per tanggal sama persis dgn baris kreatornya. Video lama tanpa views di rentang tak ditampilkan. Riwayat mulai sejak fitur aktif. **Sort per kolom** (pola Database KOL: header = tautan `sort`/`dir`, panah ↑/↓): Kreator, Diposting, tiap tanggal, Total, GMV — kolom angka klik pertama = terbesar dulu, Kreator A→Z, klik lagi balik arah; nilai ngawur/tanggal di luar rentang → default Total terbesar (daftar putih di `KolViewsHarianController::urutan()`); urutan ikut tombol Tampilkan & Export Excel. Test: `KolViewsHarianTest`.
- **Isi mundur** `tiktok:affiliate-views-backfill --dari=YYYY-MM-DD [--sampai=…] [--simpan]`: potret pagi yang terlewat disimulasikan dgn meminta Analytics `shop_videos/performance` kumulatif bulan s/d hari sebelumnya (`start_date_ge` = awal bulan, `end_date_lt` = tanggal potret; potret tgl 1 → periode bulan lalu) lalu ditulis sbg `captured_on` tsb (`TikTokAffiliateService::isiMundurPotretHarian`, logika username→KOL & tulis potret sama dgn sync harian). Hari D butuh potret D & D+1. Video yang baru muncul di potret saat potret kemarin sudah tercatat dihitung penuh (absen = 0 views bulan ini); bila kemarin belum dipotret (awal pencatatan/celah sync) hanya titik awal. Batas 60 halaman/hari tercatat di log bila terlampaui. **Default simulasi**; potret asli tak pernah ditimpa; `--sampai` default sehari sebelum potret asli pertama; galat API per tanggal dilaporkan lalu lanjut (= batas riwayat TikTok). Data bulanan Tim Gapok tak disentuh. Riwayat yang sudah tersimpan tetap ada walau TikTok menghapus datanya (tak ada pemangkasan; KOL soft-delete). Test: `KolViewsHarianBackfillTest`.

### KSS setengah otomatis + filter skor
- `KolScoringService::kssPrefill(Kol)` — tebak isian KSS dari data yang ada: ratecard (screening), median (screening → fallback avg views TikTok), ER (TikTok), niche (kategori Skinfluencer/Makeup → beauty), riwayat (verdict deal selesai; tanpa deal → "belum pernah"), kesiapan (jumlah video+LIVE jualan 30 hari ≥8 aktif / >0 jarang / 0 tidak). null = isi manual. Kalkulator (`kol-skor.kss?kol={id}`) mengisi otomatis + menampilkan sumber tiap isian; tombol "hitung" di kolom KSS Database KOL.
- **KSS otomatis di tabel** — `KolScoringService::kssAuto(Kol)`: bila ratecard + views + engagement ada, skor dihitung tanpa klik (tampil miring "≈62"); isian non-inti yang tak diketahui diisi nilai tengah & dicatat sebagai asumsi (tooltip). Filter/urut KSS memakai skor tersimpan, lalu estimasi. "hitung" = data belum cukup.
- Database KOL: filter & urut **APS** (bina_intensif/pantau/nurture/new/belum, gated `kol.affiliate.view`) dan **KSS** (shortlist/nego/tolak/belum) dari skor terakhir `kol_scores`.
- Tombol **(?)** penjelasan istilah (APS, KSS, GPM, Porsi SKINKU): partial `kols._hint` + `kols._hint-dialog` (satu `<dialog>` per halaman). Test: `tests/Feature/KolKssOtomatisTest.php`.

### Tracker performa TikTok (migrasi `000157`)
- Sumber: TikTok Creator Marketplace `GET /affiliate_seller/202608/marketplace_creators/{open_id}` (`TikTokClient::getMarketplaceCreatorPerformance`) — **ringkasan 30 hari saja** (avg views video jualan, jumlah video/LIVE, engagement, GMV + split video/LIVE, GPM, unit terjual, kolaborasi brand, komisi). **Tidak** ada views per video / tren harian di API ini.
- `TikTokAffiliateService::mapCreatorPerformance()` (murni, dites dgn JSON probe asli): **semua uang Rupiah** (USD × `services.tiktok_affiliate.usd_idr_rate`), rate/persen TikTok basis 10.000 (250 = 2,5%). `applyPerformanceToKol()` → `kol_tiktok_profiles` (angka terbaru, kolom baru + `performance_synced_at`) + `kol_tiktok_snapshots` (1 baris/KOL/hari, unik `kol_id+captured_on`).
- `tiktok:kol-performance-sync` — **mingguan Senin 13:15**, hanya KOL yang sedang kerja sama (status aktif / deal berjalan / role affiliate|both / Gapok) **dan** punya `open_id`; `--limit=30 --sleep=10 --stale-days=6`; berhenti sopan saat rate limit `36009002`. Tombol **Perbarui performa** di Detail KOL (`POST kols/{kol}/tiktok-performance`, izin `kol.affiliate.manage`).
- UI: Detail KOL — kartu metrik + grafik tracker (Chart.js, ≥2 snapshot). Database KOL — kolom **Avg Views · Engagement · GPM · GMV Video·LIVE** (uang di balik `kol.affiliate.view`). Test: `tests/Feature/KolPerformanceTrackerTest.php`.

---

## 16. Report Bot (Telegram)

**Tujuan:** migrasi bot Telegram n8n → Laravel — front-end chat untuk 2 keluarga laporan: **Leads/Ads (narasi AI → HTML)** + **parser TikTok Income (CSV+XLSX → XLSX gabungan, tanpa AI)**. Zero-dependency (cuma `Http` facade, tanpa SDK Telegram).

### Data (migrasi `000073`)
- `telegram_bot_chats` (`TelegramBotChat`) — 1 baris per chat yang pernah kontak bot: `authorized_at`, `last_used_at`, `is_blocked`.
- `telegram_bot_pending_files` (`TelegramBotPendingFile`) — tahan CSV sambil bot tunggu XLSX di webhook call kedua (TikTok Income butuh 2 file).

### Webhook
- **`TelegramWebhookController`** — route publik `POST /telegram/webhook` (tanpa auth; diamankan bandingkan `X-Telegram-Bot-Api-Secret-Token` vs `config('services.telegram.webhook_secret')`). Kirim `200` ke Telegram **segera** via `fastcgi_finish_request()` sebelum jalankan dispatcher (Telegram tak nunggu/redeliver kerja AI lambat); error dispatcher ditelan ke log.

### `app/Services/ReportBot/`
- **`ReportBotGate`** — gate kode-akses per-chat. Satu kode global (`AppSetting`) membuka chat permanen sekali dimasukkan; admin bisa `is_blocked` cabut tanpa rotasi kode global.
- **`ReportBotRouter::detect()`** — klasifikasi filename/MIME murni → `leads` | `ads` | `tiktok_income` | `null`.
- **`ReportBotDispatcher`** — gate → router → orkestrasi flow (`match()` per flow).
- **`TelegramClient`** — wrapper Bot API tipis; memusatkan fix keamanan (pesan `ConnectionException` Guzzle bisa bocorkan token bot di URL — ditangkap & rethrow generik).
- **`ReportAi`** — wrapper `AiProviderFactory`: `readFile()` (multimodal PDF/gambar → JSON) + `analyze()` (system+JSON → narasi). Stateless.
- **`TikTokIncomeN8nService`** (550 baris) — port node "Code Parse income" n8n.

### `Flows/`
- **`LeadsReportFlow`** — download PDF → `readFile` (multimodal, PDF Leads CID-font tak terbaca sbg teks) → agregasi H-1/periode pure-PHP → `analyze` per CS → HTML gabungan → `sendDocument`.
- **`AdsReportFlow`** (695 baris) — cabang tipe file: `.xlsx` (murah, tanpa AI vision — `SpreadsheetReader`) vs `.pdf` (`PdfTextExtractor`, fallback AI multimodal kalau tak terbaca) → konvergen ke JSON sama → `analyze` → HTML.
- **`TikTokIncomeFlow`** — **tanpa AI**; butuh 2 webhook delivery (CSV lalu XLSX), state di-persist ke `storage_path('app/report-bot/...')` + baris `TelegramBotPendingFile`. Output XLSX gabungan via `XlsxWriter`.

### Admin UI
`ReportBotAdminController` — `rotate()` (kode akses global baru) + `revokeChat()` (blok 1 chat), di bawah Pengaturan Sistem (`permission:system_settings`).

---

## 17. AI Assistant (embedded)

**Tujuan:** asisten chat dalam-portal yang bisa baca data bisnis live + delegasi kerja (kartu Kanban, board Mindmap), dengan backend LLM yang bisa ditukar. Spec: `AI_ASSISTANT_SPEC.md`.

### Abstraksi provider (`app/Services/Ai/`)
- **`AiProvider`** (interface) — `chat(messages, tools): AiTurn`. Format pesan/tool netral internal → tak ada kode fitur yang tahu LLM mana aktif.
- **`OpenAiProvider`** — satu-satunya implementasi konkret (OpenAI-compatible `/chat/completions`).
- **`ConcurrentAiProvider`** — opsional `chatMany()` (dipakai panel 3-spesialis OKR).
- **`FailoverAiProvider`** — bungkus daftar provider terurut, auto-switch ke backup saat gagal kuota/billing/outage. Idempotent.
- **`AiProviderFactory::make()`** — satu tempat pilih "otak": provider/model dari `AppSetting('ai_provider'/'ai_model')` fallback `config('services.ai.*')`; auto-bungkus `FailoverAiProvider` kalau backup dikonfigurasi. ⚠️ Anthropic didefinisikan tapi **tak diimplementasi** (memilihnya throw).

### Agent loop
- **`AiAgentService::run(user, history, message)`** — loop terbatas (`max_iterations`, default 5): model minta tool **read** → dieksekusi langsung, hasil di-feed balik; model minta tool **write** → loop **berhenti**, kembalikan `AgentResult{type:'confirm'}` (**tak pernah auto-eksekusi**); tak ada tool → teks final. System prompt beda staf vs mitra (mitra hanya boleh bahas akun sendiri) + inject `AiKnowledge::document()` (staf).
- **`AgentResult`** — value object: `text` atau `confirm` (nama tool + args + preview).
- History tersimpan **teks-saja** (round-trip tool tak di-persist), session-scoped (`ai_thread`), clear saat logout/reset.

### Tools (`app/Services/Ai/Tools/`) — `ToolRegistry` filter by izin
| Tool | R/W | Izin | Fungsi |
|---|---|---|---|
| `ringkas_dashboard` | read | (akses halaman) | Angka dashboard riil via `ReportService`. |
| `ringkas_kpi_kanban` | read | `kanban.view` | KPI Kanban per-orang. |
| `ringkas_mindmap` | read | `mindmap.view` | List/struktur board mindmap. |
| `buat_kartu_kanban` | **write** | `kanban.view` | Buat kartu task (tanya klarifikasi kalau ambigu). |
| `buat_mindmap` | **write** | `mindmap.view` | Buat board mindmap baru. |
| `tambah_mindmap` | **write** | `mindmap.view` | Tambah sticky/branch ke board. |
| `laporan_stok_hq` | read | `manage_hq_stock` | Laporan Mutasi Stok HQ (harian/bulanan, saring produk) via `HqStockReportService`. `nilai_hpp` di total khusus `view_hpp`. |
| `daftar_po` | read | staff & mitra (= `business`) | PO + status bayar + sisa tagihan. **Mitra hanya PO miliknya.** |
| `stok_marketplace` | read | `manage_marketplace_stock` | Stok etalase Produk Master per channel (varian & bundle terhitung). |
| `pesanan_marketplace` | read | `manage_tiktok` / `manage_shopee` | Ringkas pesanan; channel tampil hanya bila punya izin channel itu. |
| `komisi` | read | mitra / staff + `view_commission_report` | Mitra: saldo & riwayat sendiri. Admin: rekap per mitra. |
| `views_harian_kol` | read | `kol.affiliate.view` + `kol.view` (route bersarang) | Views Harian SKINKU: kreator teratas (views, Diposting, GMV, hari terbaik) atau rincian per video satu kreator — via `KolViewsHarianService`, angka = tabel halaman. |
| `data_kol` | read | `kol.view` | Database KOL: profil 1 kreator (peran, level, followers, status, gapok, KSS, pipeline, deal) atau ringkasan + daftar teratas (saring peran/gapok; status & kategori sengaja bukan filter — data nyata semua "prospek" & kategori kosong). Kolom ikut halaman: GMV (dirinci LIVE / video / lainnya = etalase & link, di profil & daftar)/pesanan/komisi/APS/jumlah video-LIVE/gaji-ROI gapok hanya + `kol.affiliate.view` (via `KolGapokService::performa`, = Tim Gapok); biaya & status bayar deal hanya + `kol.deal.finance`. Telepon/manajer/catatan/rekening **tak pernah** dikirim ke AI. |
| `produk_master` | read | `manage_products` | Katalog Produk Master: harga per tier, berat, stok pusat, status, HPP (khusus `view_hpp`) (cari nama/SKU/kategori; terhapus tak ikut) — = `ProductController@index`. |
| `pemantauan_stok` | read | staf & mitra (= `business`) | Pemantauan Stok / Stok Saya: **mitra hanya stok miliknya (qty > 0)**; staf semua mitra + total per produk + stok pusat (bila `manage_hq_stock`). Menipis = minimum > 0 & qty ≤ minimum (aturan halaman). Hanya nama mitra, tanpa kontak. |
| `retur` | read | `process_return`, atau mitra | Retur PO: status, barang×qty, kondisi, alasan, kredit (applied). Tanpa `process_return` → **hanya retur atas PO miliknya** (= gerbang `ReturController@index`). |
| `bahan_baku` | read | `manage_production` | Bahan baku: stok, status + riwayat beli (qty, nama supplier); HPP rata-rata, nilai stok, harga beli, HPP sebelum→sesudah khusus `view_hpp`. |
| `produksi_hpp` | read | `manage_production` | Batch produksi: qty, pencatat, ringkasan per produk; `nomor` → rincian bahan yang dipakai (= halaman detail). Total biaya, HPP/pcs, HPP rata-rata, harga bahan & biaya lain khusus `view_hpp`. Kolom tersimpan, tak dihitung ulang. |
| `stok_opname` | read | `manage_hq_stock` | Riwayat opname dari mutasi HQ `reference_type='opname'` (dicatat 1 detik sebelum tgl opname → dikelompokkan per tgl opname): produk disesuaikan, selisih fisik − sistem. Menu Stok Masuk (beli jadi) sengaja tak dibuatkan alat (menunya disembunyikan). |
| `laporan_penjualan` | read | `view_reports` | Laporan Penjualan (staf) / Laporan Pembelian (mitra = PO miliknya): total PO selesai, PO per status, produk terlaris; staf + per mitra & per wilayah; **laba kotor khusus `view_hpp`** (selain itu `catatan_akses`). `ReportService` sama dgn halaman. |
| `omzet_mitra` | read | `view_reports` + staf | Omzet Mitra: jual ke downline + jual ke customer per mitra (`ReportService::omzetPerMitra`), 30 teratas, saring `cari` nama (kosong → catatan "ulangi tanpa cari"). |
| `penjualan_downline` | read | `view_reports` + mitra stockist (`PartnerHierarchy::holdsStock`) | Penjualan akun itu ke downline (`downlineSalesReport`): net setelah retur, PO masuk/pending/selesai, per downline & per produk — hanya miliknya. |
| `laporan_bisnis` | read | `view_reports` + staf | Generate Report: `BusinessReportService::build()` → `insight_input` (angka saja, tanpa data pribadi — sama dgn analisis AI halaman); jenis mingguan/bulanan/kuartal/tahunan/custom/semua; laba rugi ikut `view_accounting` + `view_hpp`, KOL ikut `kol.affiliate.view` (+ `catatan_akses`). |
| `laporan_keuangan` | read | `view_accounting` + `view_hpp` | Akuntansi: Laba Rugi (+margin, akun terbesar), Neraca, Arus Kas satu bulan buku (`FinancialReportService` + `CashFlowService`); default bulan buku terakhir (`AccJournal::periodeBuku()`, dipakai juga dropdown halaman); bulan tanpa jurnal → catatan. Jurnal/COA sengaja tanpa alat. |
| `penarikan` | read | `process_withdrawal` + staf | Penarikan komisi: jumlah & total per status (diajukan/disetujui/cair/ditolak), `perlu_diproses`, 20 terbaru — **tanpa** bank/no rekening/atas nama/catatan. Mitra lihat penarikannya lewat `komisi`. |
| `struktur_jaringan` | read | `manage_users` + staf | Struktur Jaringan: jumlah mitra per tier (aktif/nonaktif) & wilayah, belum ditempatkan (tier < teratas tanpa upline), upline dgn downline langsung terbanyak; `cari` → detail satu mitra (upline, downline langsung, total seluruh downline via `PartnerHierarchyService::descendants`). Tanpa kontak. |
| `jaringan_saya` | read | mitra yg punya downline | Jaringan Saya: `NetworkSummaryService::summarize` (pohon diratakan, 50 omzet terbesar): anggota, aktif jualan 30 hari, omzet jaringan bulan ini (nota penjualan downline), tren 3 bulan — hanya jaringannya, tanpa data customer. |
| `rekrutan_saya` | read | mitra yg punya rekrutan | Rekrutan Saya: `CommissionService::ringkasanRekrutan` (dipakai juga halamannya): rekrutan, baru bulan ini, bonus join + RO cashback per rekrutan & total, saldo bisa ditarik. |
| `dormansi_member` | read | `manage_member_dormancy` + staf | Dormansi Member: `MemberDormancyService::panel` (dipakai juga halamannya): aturan per role, sudah beku, akan beku ≤ 14 hari, ditahan (punya downline aktif) + **mitra aktif paling lama tidak order** (`terakhirOrder()` = definisi basis order dormansi; belum pernah order paling atas). |
| `paket_join` | read | `manage_join_packages` + staf | Paket Join: paket (untuk tier, harga, aktif, isi produk) + jumlah & rupiah bergabung per periode dan total dari `join_transactions` (batal tak dihitung, tanpa nama member). |
| `pesanan_downline` | read | `process_downline_po` + mitra | Pesanan Downline: hanya PO `seller_id` = dia — per status (jumlah & rupiah), perlu tindakan (bukan draft/selesai/batal) dgn status bayar, 15 terbaru. Tanpa catatan/alamat/bukti bayar. |
| `tim_gapok` | read | `kol.affiliate.view` + `kol.view` (route bersarang) | Tim Gapok: per anggota GMV (LIVE/video), pesanan, komisi, video/LIVE, gaji (otomatis = ikut bulan lalu), dibayar/kurang, ROI + total & ROI tim — `KolGapokService::range/totals` (= tabel). Bulan atau rentang `dari`–`sampai` (gaji = bulan tanggal mulai, aturan halaman). Tanpa catatan gaji/bayar. |
| `pipeline_kol` | read | `kol.view` | Pipeline: papan `kol`/`affiliate`, jumlah per tahap, statistik header via `KolPipelineCard::statistik()` (dipakai juga papan), kartu aktif paling mendesak (next action, terlambat N hari, rate diminta→final); `tahap` (kunci/label) → kartu tahap itu, tahap papan lain → pindah papan. Tanpa catatan & catatan nego. |
| `deal_kol` | read | `kol.deal.manage` (tanpa `kol.view`, = route) | Deal KOL: daftar (scope `KolDeal::bulan()` = filter bulan halaman; tanpa bulan = semua), per status, verdict hasil + ringkasan Laporan Hasil (`KolDeal::laporanHasil()`, dipakai juga halamannya). Biaya, status bayar, sisa tagihan, budget bulan (`KolBudgetService::summary`), revenue/CPM/ROMI **hanya `kol.deal.finance`** (selain itu `catatan_akses`). Rekening/catatan internal/catatan hasil tak pernah dikirim. |
| `reminder_kol` | read | `kol.view` | Reminder: `KolReminderService::untuk()` (dipakai juga halamannya) — kartu terlambat/hari ini/besok/tanpa next action, deadline posting ≤3 hari; sampel tertahan (`kol.deal.manage`), affiliate berhenti posting (`kol.affiliate.view`), tagihan belum lunas + sisa (`kol.deal.finance`), bagian tanpa izin → `catatan_akses`. Tanpa nomor resi/catatan/rekening. |
| `konten_views_kol` | read | `kol.view` | Konten & Views: `KolKontenService::bulan()` (dipakai juga halamannya) — total views paid/earned, target (override bulan > global), % capaian, proyeksi, butuh views/hari, sebaran label/platform/tipe, kreator & konten teratas (+ER, deal, link); `username` → satu kreator tanpa perbandingan target tim. Tanpa catatan konten. |
| `pipeline_konten` | read | `content.create` | Pipeline & Kalender Konten brand: angka kartu tahap (`ContentPost::hitungTahap`) + daftar (jadwal, status, status & link per platform, alasan gagal) dgn saring tahap/dari–sampai/platform/judul; **creator hanya kontennya** (`terlihatOleh`), pengelola semua + saring kreator (nama/username, ambigu → tanya balik). Tanpa caption & catatan. |
| `insight_konten` | read | `content.manage` | Insight Konten: `KontenInsightService::ringkasan()` (dipakai juga halamannya) — views, interaksi, ER, postingan (ada data / belum), error sinkron, 10 teratas (+link), per creator; 7/30/90 hari, semua/IG/TikTok. |
| `akun_sosmed` | read | `social.connect` | Akun Sosial Media: per platform terhubung/belum, nama akun, aktif/bermasalah, token berlaku s/d, error terakhir & error insight, dihubungkan oleh; app siap; kredensial **hanya terisi/belum + sumber** — token, refresh token, nilai kredensial & account_id tak pernah dikirim. |
| `supplier` | read | `manage_production` | Supplier: nama, aktif/nonaktif, bahan yang pernah dibeli, jumlah & tanggal beli terakhir (`material_purchases`); total pembelian (Rp) khusus `view_hpp` (selain itu `CATATAN_HPP`). Telepon/alamat/catatan supplier tak pernah dikirim. |
| `kalkulator_roi` | read | `manage_roi_calculator` | Kalkulator ROI: `RoiCalculatorService::rowFor/summary` (= tabel) — setelan potongan global + harga jual; modal, total biaya, profit (± affiliate), BEP ROI, target ROI 5/10/15/20% & rata-rata patokan **khusus `view_hpp`** (modal default = HPP produk, profit/target bisa membuka HPP) → admin hanya setelan & harga jual + `catatan_akses`. |
| `okr` | read | `okr.view` + bukan mitra (= middleware `internal`) | OKR: siklus (periode, cakupan, draft/aktif, progres = tugas Kanban selesai ÷ total, rumus halaman via `OkrTask::isCompleted`), objective per divisi; `nama` (nama/label periode) → rincian KR (metrik, baseline, target, tenggat, PIC) & tugas selesai/belum; ambigu → tanya balik. |
| `academy` | read | `view_learning` | SKINKU Academy: modul & materi dari `LearningModule/Lesson::terlihatUntuk()` (dipakai juga halamannya: pengelola semua, lainnya terbit & sesuai audiens); `cari` → materi cocok + deskripsi + link. Isi video/dokumen tak dibaca. |

Tool write selalu lewat alur confirm; tool read eksekusi inline.

**HPP & biaya modal (izin `view_hpp`)** — "Lihat HPP, Harga Beli, Biaya Produksi & Laporan Keuangan (halaman & Asisten AI)"; default kosong = hanya super admin, bisa diberikan di Hak Akses. Admin & gudang tetap bisa **mencatat** (produk, beli bahan, produksi, stok masuk) tapi tak melihat hasil hitungan HPP/biaya:
- **Halaman:** Produk Master (kolom & field HPP, tombol Riwayat HPP, HPP tak ikut JSON edit), Bahan Baku (HPP rata-rata, nilai stok, harga beli & HPP di riwayat beli, field HPP manual), Produksi (total biaya, HPP/pcs, biaya bahan/lain, kolom harga di form input/ubah, HPP bahan tak ikut JSON `MATERIALS`, notifikasi simpan tanpa angka HPP), **Riwayat HPP** (`products.hpp-history` → 403), Laporan Stok HQ (HPP/unit & nilai HPP), Stok Masuk (total biaya, harga beli, HPP sebelum/sesudah, HPP di form), Laporan Penjualan (kartu HPP/laba kotor/margin), **Kalkulator ROI** (2026-10-08: kolom Modal, Profit Bersih, BEP & Target ROI, rincian profit, rata-rata patokan, field Modal di form — modal kiriman tanpa izin diabaikan server; admin tetap lihat harga jual & potongan platform).
- **Server:** `cogs` produk tak divalidasi/diterima (produk baru = 0, edit = HPP lama); `avg_cost` bahan manual diabaikan; harga bahan ketikan di produksi diabaikan (→ HPP rata-rata bahan); ubah produksi mempertahankan harga baris lama per bahan.
- **Asisten AI:** `produk_master`/`bahan_baku`/`produksi_hpp`/`laporan_stok_hq` tak mengirim angka modal + `catatan_akses` (`BaseTool::CATATAN_HPP`, cek `BaseTool::bolehLihatHpp()`).
- **Laporan keuangan** (2026-10-08): Laba Rugi, Neraca, Arus Kas, Neraca Saldo, Banding, Tren + bagian Keuangan Generate Report (Excel & AI) butuh juga `view_hpp`; admin tetap input/impor jurnal, COA & template. Test: `tests/Feature/HppIzinTest.php`; tes lama ProductionTest & SupplierMaterialTest memberi admin `view_hpp` di `setUp()`.

**Aturan alat per menu:** izin alat = izin route menunya (`permission()`), syarat tambahan (mis. `business`, salah satu dari dua izin) lewat `availableFor(User)`; `ToolRegistry` mengecek keduanya — alat yang tak lolos tak dikirim ke model dan tak bisa dipanggil by name. Scoping data mitra (milik sendiri) dilakukan **di dalam** alat. Jangan buat alat generik "query bebas" — melewati batas role. Test: `tests/Feature/AiMenuToolsTest.php`, `tests/Feature/AiKolToolsTest.php` (alat KOL: izin per role, kolom per izin, tanpa data pribadi), `tests/Feature/AiOperasionalToolsTest.php` (Produk & Operasional), `tests/Feature/AiKolLanjutanToolsTest.php` (Tim Gapok, Pipeline, Deal, Reminder, Konten & Views: izin per role, angka = halaman, biaya hanya finance), `tests/Feature/AiKontenToolsTest.php` (Pipeline/Kalender, Insight, Akun Sosmed: cakupan creator vs pengelola, tanpa token/kredensial), `tests/Feature/AiLainLainToolsTest.php` (Supplier, Kalkulator ROI, OKR, Academy). `ringkas_dashboard` stok menipis memakai aturan halaman (minimum > 0) & jumlahnya dihitung penuh (bukan dari contoh limit 10). Username → KOL (persis/alias) lewat `KolUsernameAlias::kolId()` — sama dgn sync affiliate; nama sebagian → kandidat utk ditanyakan balik.

### Controller / route
`/asisten` (halaman penuh) + widget floating → backend JSON-or-redirect sama: `state` (poll), `send` (POST → mungkin `confirm`), `confirm` (eksekusi 1 tool write pending setelah `validate()` re-run defensif + audit-log), `reset`. `/asisten/pengetahuan` (**"Pengetahuan AI"**) digating tambahan `internal`.

### Knowledge base — `AiKnowledge` (`ai_knowledge`, `000060`)
1 baris per seksi terpandu (business/products/team/workflow/priorities/okr_strategy/rules/notes). `document()` gabung seksi terisi (≤6000 char) jadi blok "PENGETAHUAN BISNIS" di system prompt — **eksplisit dibingkai sebagai data, bukan instruksi** (hardening prompt-injection).

**Uji chat pembeli** (2026-10-09, tab Chat E-commerce): panel kanan `ai/_uji_chat.blade.php` → `POST /asisten/pengetahuan/uji-chat` (`ai.knowledge.test-chat`, grup sama dgn simpan pengetahuan: `use_ai_assistant` + `internal`, `throttle:20,1`). Pengetahuan dirangkai dari isian form saat itu lewat `AiKnowledge::documentFrom('chat', …)` (belum disimpan pun; `document()` kini memakai fungsi yang sama), riwayat uji dikirim dari browser, lalu `EcomChatDrafter::uji()` — prompt, aturan keputusan (`auto_send`/`to_staff`) & fail-safe SAMA dgn `draft()` chat sungguhan. Hasil {reply, decision, reason} ditampilkan dgn label "Terkirim otomatis"/"Diteruskan ke staf" + alasan. Tidak menyimpan & tidak mengirim apa pun. Test: `tests/Feature/UjiChatEcommerceTest.php`.

**Izin:** `use_ai_assistant` (default: staf + semua role mitra).

---

## 17b. Rekomendasi AI (Discovery)

Menu **Rekomendasi AI** (`discovery.index`) — AI mencari di web real-time lalu merangkum kandidat KOL baru atau tren produk. Beda dari sorting KOL internal: ini menemukan yang **belum ada** di database.

### Arsitektur
- **Mesin pencari swappable:** `WebSearchProvider` (interface) + `TavilyProvider` (Tavily, `TAVILY_API_KEY`) + `WebSearchFactory::make()` (pilih dari `config/services.php` → `discovery.provider`; tambah Serper/Brave = tambah cabang match). Di-bind lazy di `AppServiceProvider` (di-swap `FakeWebSearchProvider` saat test).
- **Perangkum:** reuse `AiProvider` (`AiProviderFactory::make()`, OpenAI). Prompt **grounded/anti-ngarang** — AI hanya boleh pakai potongan hasil pencarian, wajib sertakan URL; kandidat/poin tanpa link dibuang di `AiDiscoveryService`. Hasil web kosong → AI tak dipanggil (hemat token).
- `AiDiscoveryService::discoverKols(brief)` & `productTrends(topik)`.

### Alur
- **Cari KOL:** brief (kategori/platform/region/follower min-max/keyword) → kandidat (username, est. follower, kategori, link, alasan) → tombol **+ Tambah ke Database KOL** → `KolService::createProspek()` (status `prospek`, dedupe by username case-insensitive, followers lama tak ditimpa) → redirect detail KOL → lanjut screening biasa.
- **Tren Produk:** topik → laporan **read-only** (ringkasan + poin + link sumber), tak disimpan.

**Izin:** `use_ai_discovery` (default: admin + kol_specialist), **internal-only** (mitra diblokir `InternalOnlyMiddleware`). Aksi tambah KOL butuh `kol.screening.manage` lagi (admin non-super hanya bisa mencari). Zero-dependency (HTTP + `TAVILY_API_KEY`, tanpa migrasi).

---

## 18. SKINKU Academy (LMS)

LMS sederhana: **`LearningModule`** (grup, sort, flag publish) punya banyak **`Lesson`** (video via URL YouTube dan/atau dokumen ter-upload PDF/PPT/Word/Excel — keduanya opsional tapi minimal satu wajib). Migrasi `000012`/`000013`/`000015`.

Daftar halaman = `LearningModule::terlihatUntuk()` + `Lesson::terlihatUntuk()` (dipakai juga alat AI `academy`). `Lesson::visibleTo()` gate by `is_published` + array `audience` (role names; kosong = semua; super_admin selalu lihat). Preview dokumen: iframe native untuk PDF, embed Microsoft Office Online untuk file Office.

**Izin:** `view_learning` (luas — termasuk reseller) / `manage_learning` (admin). Sidebar: **"SKINKU Academy"**.

---

## 19. Material, Produksi, Supplier

Bagian **Materials & Production** (izin `manage_production`, routes/web.php ~L213-237):
- **`Supplier`** (`suppliers`, `000023`) — master minimal (nama/telp/alamat/catatan/status) → feed `MaterialPurchase`. `nullOnDelete` FK (hapus supplier tak cascade-hapus riwayat beli).
- **`Material` / `MaterialPurchase`** (`000018`/`000019`/`000024`) — bahan baku + pembelian (untuk HPP/costing produksi).
- **`Production` / `ProductionMaterial` / `ProductionCost`** (`000020`-`000022`) — batch produksi barang jadi → menyumbang `hq_stock` + `cogs`.

**Izin:** `manage_production` (material/supplier/produksi), `receive_stock` (penerimaan).

---

## 19b. HR — Karyawan, Rekrutmen, Payroll

Spec: `docs/superpowers/specs/2026-10-10-hr-design.md` (disetujui user 2026-10-10). Bertahap: **Fase 1 Karyawan
(SELESAI)** → Fase 2 Rekrutmen + Psikotes → Fase 3 Payroll. Grup sidebar **HR** (setelah Produktivitas), semua route
`/hr/*` di balik `internal` (mitra diblok keras) + izin.

**Fase 1 — Karyawan** (`HrEmployeeController`, `App\Models\Employee`, migrasi `000162` tabel `employees`):
- Kode `KRY-0001` otomatis (event `created`), tautan opsional ke akun portal staf (`user_id` unik, akun mitra ditolak),
  status kerja (percobaan/kontrak/tetap/magang/harian) + status aktif/keluar (keluar wajib tanggal).
- **Data identitas** `Employee::SENSITIF` (NIK, NPWP, alamat, no/atas nama rekening, BPJS, kontak darurat) cast
  `encrypted` + `$hidden`; tampil & bisa diubah hanya dgn `hr.manage`.
- **Onboarding**: checklist tetap `Employee::ONBOARDING` (KTP & KK, NPWP, Rekening, BPJS, Kontrak) di kolom JSON
  `onboarding` (`{item: {selesai, oleh}}`) + dokumen per item via `ImageService::attach(..., disk: 'local')` (parameter
  disk baru, default tetap `public`) → koleksi `hr_{item}` di **disk privat**, diunduh lewat
  `hr.employees.documents.show` (cek kepemilikan file, `hr.manage`), bukan URL publik.
- **Pengingat**: masa percobaan / kontrak berakhir ≤ 30 hari (atau lewat) → kartu ringkas + label di daftar & detail.
- **Audit**: `create_employee` / `update_employee` (nilai non-sensitif sebelum → sesudah; kolom identitas hanya
  namanya di `data_identitas_diisi`/`data_identitas_diubah`), `employee_onboarding`, `upload_/view_/delete_employee_document`.
- Tidak ada alat Asisten AI untuk HR (data pribadi).

**Izin:** `hr.view` (data kerja, default `admin`), `hr.manage` (kelola + identitas & dokumen, default super admin).
Test: `tests/Feature/HrEmployeeTest.php`.

---

## 20. Cron / Terjadwal

Didefinisikan di `routes/console.php`:

| Jadwal | Command | Fungsi |
|---|---|---|
| Tiap 30 mnt | `tiktok:sync` | Sync order TikTok (base) + auto-potong |
| Harian 01:00 | `tiktok:sync --returns --settlements` | Retur + settlement TikTok |
| Per jam | `tiktok:describe` | Isi "kind" settlement (skip kalau tak ada backlog) |
| Harian 03:30 | `tiktok:sync --full` | Sweep safety-net TikTok |
| Tiap 30 mnt | `shopee:sync` | Sync order Shopee (base) + auto-potong |
| Harian 01:15 | `shopee:sync --returns` | Retur Shopee |
| Harian 01:30 | `shopee:sync --settlements` | Settlement/escrow Shopee |
| Harian 01:45 | `shopee:sync --wallet` | Wallet Shopee |
| Tiap 5 menit | `marketplace:push-stock` | Push stok+harga Produk Master (§9c) yang berubah ke TikTok & Shopee |
| Harian 02:30 | `db:backup` | Backup DB (safety-net) |

**Manual/CLI only (tanpa cron):** `tiktok:backfill`, `tiktok:audit`, `shopee:ping`, `stock:reconcile-hq`, `po:purge`. Posting jurnal akuntansi **tetap manual/opt-in** (saklar `journal_enabled`, tombol post) — bukan cron.

---

## 21. Referensi Izin (lengkap)

Dari `app/Support/Permissions.php`. super_admin selalu punya semua (terkunci). Default bisa di-override per-role via `/permissions`.

| Kunci izin | Default pemilik | Untuk |
|---|---|---|
| `manage_permissions` | super_admin | Matriks izin + custom role |
| `manage_users` | admin | User, onboarding, struktur jaringan |
| `manage_products` | admin | Katalog produk |
| `manage_production` | admin | Material, supplier, produksi, HPP |
| `receive_stock` | admin/gudang | Penerimaan stok |
| `manage_hq_stock` | admin/gudang | Adjust HQ, movement, opname, laporan HQ, sale backdate |
| `create_po` | (mitra + staf) | Buat PO |
| `update_po_status` | admin | Jalankan status PO HQ |
| `delete_po` | admin | Hapus PO |
| `process_downline_po` | (mitra stockist) | Fulfil PO downline |
| `process_return` | admin | Approve/reject retur PO |
| `process_withdrawal` | admin | Proses penarikan komisi |
| `view_commission_report` | admin | Laporan komisi |
| `manage_join_packages` | admin | Katalog paket join |
| `view_reports` | staf | Laporan penjualan |
| `view_accounting` | admin | Jurnal, COA, template & impor akuntansi (laporan keuangan butuh juga `view_hpp`) |
| `delete_accounting` | (kosong) | Hapus jurnal permanen |
| `manage_tiktok` | admin | Integrasi TikTok |
| `manage_shopee` | admin | Integrasi Shopee |
| `okr.view` / `okr.manage` | staf/internal | OKR |
| `kanban.view` | internal | Kanban |
| `mindmap.view` | internal | Mindmap |
| `kol.view` / `kol.screening.manage` / `kol.deal.manage` / `kol.deal.approve` / `kol.deal.finance` / `kol.report.view` / `kol.pipeline.manage` / `kol.content.manage` / `kol.affiliate.view` / `kol.affiliate.manage` | kol_specialist (+admin) | KOL |
| `use_ai_assistant` | staf + mitra | AI Assistant |
| `view_learning` / `manage_learning` | luas / admin | SKINKU Academy |
| `system_settings` | admin | Pengaturan sistem (termasuk Report Bot) |
| `hr.view` / `hr.manage` | admin / — | HR → Karyawan: data kerja / kelola + data identitas & dokumen (§19b) |

> Nilai default persisnya lihat `DEFAULTS` di `app/Support/Permissions.php` — tabel ini ringkasan fungsi, bukan salinan verbatim.

---

## 22. Peta Migrasi

95 migrasi domain (di luar 3 bawaan Laravel). Rentang penting:

- **`000000`–`000026`** — fondasi: users, products, PO, inventory, stock_movements, audit, roles, akuntansi (`000000` acc tables + `000025` template + `000026` import_hash), Academy, material/produksi/supplier.
- **`000030`–`000041`** — TikTok (connections/orders/sku_maps/returns/settlements + kolom inkremental + fix zona waktu).
- **`000042`–`000044`** — Shopee awal (`000042`), dukungan sale backdate (`000043`), partner sales (`000044`).
- **`000045`–`000049`, `000054`-`000055`, `000067`, `000072`** — KOL (master/screening/deals + agency/platform/phone/hasil/affiliate-metrics).
- **`000050`–`000051`, `000061`-`000062`** — Kanban.
- **`000056`–`000059`** — Announcement + community links.
- **`000060`** — AI Knowledge.
- **`000063`–`000066`, `000070`** — OKR.
- **`000071`** — Mindmap.
- **`000073`** — Report Bot.
- **`000074`–`000092`** — **MLM**: hierarchy ke users (`000074`), reorder role (`000075`), price_grand (`000076`/`000078`), backfill tanggal movement (`000077`/`000079`), seller_id PO (`000080`), commissions (`000081`), bank+withdrawals (`000082`/`000083`), join packages/items/transactions (`000084`-`000086`/`000091`), sponsor_id (`000087`), volume tiers (`000088`), po_returns (`000090`), city (`000092`).
- **`000093`–`000095`** — **Shopee Fase 2-4**: returns (`000093`), settlements (`000094`), accounting/wallet (`000095`).
- **`000096`–`000098`** — retur detail: `from_customer` (`000096`), `credit_amount` + backfill (`000097`/`000098`).
- **`000099`–`000100`** — **Sub-menu KOL Fase 1**: pipeline cards/events (`000099`), konten + snapshot views (`000100`).
- **`000101`** — **Sub-menu KOL Fase 3a**: transaksi affiliate (GMV/komisi per order).

Migrasi tertinggi saat ini: **`000101`**.

---

## 23. Deploy & Status Lokal vs Prod

### Model deploy (penting)
- **Claude push dari lokal → user pull di prod.** Jangan paste `git push` ke terminal SSH prod.
- Prod: SSH `-p 65002 u864765086@153.92.11.179`, dir app = `~/domains/skinku.id/laravel-b2b`, PHP `/opt/alt/php83/usr/bin/php`.
- Deploy standar:
  ```bash
  cd ~/domains/skinku.id/laravel-b2b && git pull origin main && /opt/alt/php83/usr/bin/php artisan migrate --force && /opt/alt/php83/usr/bin/php artisan optimize:clear
  ```
  (Kalau perubahan **tanpa migrasi baru** — mis. dashboard — cukup `git pull` + `optimize:clear`, tanpa `migrate`.)

### Status lokal (per commit `56d2a6a`)
- Branch `main`, **working tree bersih**, **`main` = `origin/main` persis** (0 ahead / 0 behind). Artinya: **semua yang dibangun sudah di-commit + push**. Saat prod `git pull`, prod jadi 1:1 dengan lokal.
- Fitur yang butuh `migrate --force` di prod (kalau prod belum jalankan): **`000093`/`000094`/`000095`** (Shopee Fase 2-4).
- Dashboard omzet (`56d2a6a`) **tanpa migrasi** — user sudah konfirmasi deploy.

### Yang masih perlu env di prod untuk Shopee go-live
Isi `SHOPEE_PARTNER_ID` / `SHOPEE_PARTNER_KEY` **live** + `SHOPEE_API_BASE=https://partner.shopeemobile.com` (TANPA `SHOPEE_INSECURE`) di `.env` prod → daftar redirect `.../shopee/callback` → connect toko asli. (Sandbox/lokal pakai host sandbox + `SHOPEE_INSECURE=true`.)

> ⚠️ Prod pernah punya perubahan Hermes belum-commit (`config/services.php`, `routes/web.php`, `.env.example`) → `git pull` bisa konflik; `stash`/`pop` bila perlu.

---

## 24. Catatan & Utang Teknis

- **Chat E-commerce — sumber balasan** (`ecom_chat_messages.via`): `buyer` / `ai` (AI SKINKU) / `staff` / `bot` (chatbot & sistem TikTok: role ROBOT/SYSTEM atau tipe OTHER dari toko; isi kartu OTHER tak dikirim API → `EcomChatMessage::BOT_CARD_TEXT`). `EcomChatService::refreshReplyState()` menghitung ulang status tiap tarik TikTok; `repairAiLabels()` memulihkan label AI yang tertimpa sync lama (TikTok & Shopee, hanya draft auto-send terakhir). **Sementara:** `logShopeeSource()` mencatat metadata pesan toko Shopee ke `storage/logs/shopee-chat-source.log` untuk mencari tanda auto-reply Shopee — hapus setelah deteksi bot Shopee dibuat.

Hal-hal yang **sudah teridentifikasi** dari pembacaan kode — bukan bug aktif yang menghalangi, tapi worth diketahui/dirapikan nanti:

1. **Role `sponsor` tak ada di tabel `roles`.** Konstanta dipakai logika komisi, tapi belum ada seeder yang insert baris `sponsor` → belum bisa dipilih di UI Role/Permission. (Impact: onboarding sponsor via UI belum lengkap.)
2. **`Commission.status` tak pernah flip ke `ditarik`.** Komentar kolom menyiratkan begitu, tapi implementasi tetap `saldo`; "penguncian" dana pending memakai `availableBalance()` (saldo − withdrawal belum-ditolak), bukan flip status.
3. **`StockMovement` `movement_type='paket_join'`** ditulis `OnboardingService` tapi tak ada di daftar `TYPES` (kolom tak enum-DB, jadi tak error — hanya inkonsistensi dokumentatif).
4. **`User::priceField()` vs `Product::priceForRole()`** — helper display 2-tier tak simetris dengan resolver harga 4-tier yang otoritatif. Selalu percayai `priceForRole()` untuk uang.
5. **Rumus moving-average COGS terduplikasi** — `app/Support/Costing.php::movingAverage()` + salinan inline di `StockReceiptService`. Kandidat DRY.
6. **AI Assistant: provider Anthropic belum diimplementasi** — didefinisikan di config/interface, tapi memilihnya throw. OpenAI-compatible satu-satunya yang jalan.
7. **KOL "affiliate" belum wired** — `OkrBusinessSnapshotService` menandai `source_not_available`; metrik affiliate (`000067`) ada tabelnya tapi belum jadi fitur.
8. **Shopee Fase 4.1 (non-blocking):** sign-branch `escrow_amount` negatif di `previewSettlement` sekarang fail-loud + ke-catch per-row (ikut konvensi TikTok) — belum ditangani eksplisit.
9. **Sisa Shopee opsional:** AMS/Affiliate (subsistem terpisah), Fase 1.5 polish (UI `reverse()` undo potong stok, badge pra-cutoff, tampil `shop_name`/region), go-live connect toko asli.

### Roadmap MLM (terpisah, belum dibangun — konteks arah)
- **Insentif Volume Grand** — desain dikunci (auto/tahunan/bertingkat/%total/top-up→saldo, migrasi `000088` ada) tapi engine belum dibangun penuh.
- **Model A (margin antar-mitra)** — arah dikunci: HQ urus GD, distri beli ke GD (untung margin), override dibuang. Engine antar-mitra (`seller_id`) sudah ada fondasinya.
- **Sponsor role + dual-link** — konsep dikunci (role Sponsor perekrut murni + 10% join universal + 5% cashback RO dari GD), belum ada spec/kode.

---

*Dokumen ini dirangkai otomatis dari pembacaan kode oleh 3 agent pemetaan (core+MLM, marketplace+akuntansi, reporting+lain) lalu disintesis. Kalau ada modul yang berubah, perbarui seksi terkait + baris di [Daftar Isi](#daftar-isi).*
