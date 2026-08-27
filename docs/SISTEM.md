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
- **Laporan:** `/accounting/laporan` (gabungan), `/laba-rugi`, `/neraca`, `/arus-kas`, `/banding`, `/tren`, `/neraca-saldo`.
- **Entri manual:** `/accounting/jurnal` (list), `/jurnal/baru` (form + preset template), `POST /jurnal`, `POST /jurnal/{j}/void`.
- **Hapus** (gate ketat): `DELETE /accounting/jurnal/{j}` butuh `delete_accounting` (default **kosong** — hanya super_admin).
- **Impor Excel:** `/accounting/impor-excel` (klien parse → `journals[]`, server validasi balance + dedup; flag `is_opening` tag `opening_balance`).
- **Impor mutasi bank:** `/accounting/impor` (1 jurnal per baris bank vs akun bank terpilih; `keluar` → Dr counter/Cr bank, `masuk` → Dr bank/Cr counter).
- **COA / Template:** `AccAccountController` (`/accounting/coa*`), `AccTemplateController` (`/accounting/template*`); destroy digating `delete_accounting`.

**Izin:** `view_accounting` (default admin), `delete_accounting` (default **nobody** — komentar "hapus jurnal itu permanen").

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
`okr.index/show/status` (izin `okr.view`); `okr.create/generate/update/approve/destroy` (izin `okr.manage`). `update()` biar manusia koreksi draft pra-approve (guard IDOR, whitelist member/kolom, cek tanggal-in-period). **Hard-block `internal`** (mitra tak pernah lihat OKR). Sidebar: akordeon **"Produktivitas"** (OKR + Kanban + Mindmaps).

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
- **Reminder** (`KolReminderController`, baca-saja) — agregat pipeline: terlambat → jatuh tempo hari ini → tanpa next-action (drop dikecualikan).
- **Konten & Views** (`KolContentController`, `kol_contents` + `kol_content_snapshots`, migrasi 000100) — arsip konten per KOL + **snapshot views bertanggal append-only** (unique kol_content_id+captured_on → isi ulang hari sama = replace). **Anti-dobel-hitung:** konten ber-`kol_deal_id` DIPAKSA `paid`, sisanya `earned`. Ringkasan bulanan (total/paid/earned + proyeksi pace vs `kol_views_target` AppSetting). **Grid isi views massal** (spreadsheet-like, snapshot hari ini). Autofill judul via **TikTok oEmbed** (host allowlist tiktok.com). Izin tulis `kol.content.manage`. ⚠ Lookup `captured_on` pakai Carbon `startOfDay()` (string Y-m-d meleset dari nilai datetime tersimpan).

**Izin baru:** `kol.pipeline.manage`, `kol.content.manage` (default `kol_specialist`).

### Sub-menu KOL Fase 2 — Deal & Budget

`KolBudgetService` di atas `kol_deals` (tanpa tabel/izin baru): panel budget bulanan di halaman Deal (finance-gated) — **spent** (deal lunas) / **committed** (aktif belum lunas) / **sisa** (dari cap `kol_budget_monthly`); **blended CPM paid** = biaya deal ÷ views konten paid (Fase 1) vs `kol_cpm_anchor`; warning 1-KOL>40%. Reminder tagihan deal belum lunas ikut di halaman Reminder.

### Sub-menu KOL Fase 3 — Affiliate & GMV · Skor · Agen (migrasi 000101)

Rumus di-port PERSIS dari app lokal `Iyuro/skinku`. Spec: `docs/superpowers/specs/2026-08-27-kol-fase3-*`.
- **3a Affiliate & GMV** — `kol_affiliate_transactions` (unique platform+order_id, kol_id null=belum cocok) + `KolAffiliateService` (import dedup/match, ranking bulanan kecuali batal, weeklyGmv, unmatched, apsInput). Halaman ranking GMV/komisi/order + layar **Belum Cocok** (tautkan username→KOL). **Import XLSX/CSV** auto-map header (reuse `SpreadsheetReader`). Izin `kol.affiliate.view` (angka uang) + `kol.affiliate.manage` (import/cocok).
- **3b Skor** — `App\Support\KolMetrics` (cpm/ecpm/rpm/roas/growthVelocity/consistency/median/pace) + `KolScoringService` (**APS** 4-mingguan: growth 35%+RPM 25%+konsistensi 20%+skala 20%, reweight bila views null, cap 40 bila 2mgg no-post, label ≥75 bina/≥50 pantau/<50 nurture · **KSS** kalkulator seleksi: eCPM 35%+ER 20%+niche 20%+riwayat 15%+kesiapan 10%, ≥70 shortlist/≥50 nego/<50 tolak). KSS di menu **Skor**; APS jadi kolom di ranking affiliate.
- **3c Agen** — endpoint `POST /api/kol-agent/affiliate` (header `X-Agent-Token`, CSRF-exclude, source=agent) untuk app lokal setor transaksi hasil scrape. Token `KOL_AGENT_TOKEN`.

**Izin baru:** `kol.affiliate.view`, `kol.affiliate.manage`.

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

Tool write selalu lewat alur confirm; tool read eksekusi inline.

### Controller / route
`/asisten` (halaman penuh) + widget floating → backend JSON-or-redirect sama: `state` (poll), `send` (POST → mungkin `confirm`), `confirm` (eksekusi 1 tool write pending setelah `validate()` re-run defensif + audit-log), `reset`. `/asisten/pengetahuan` (**"Pengetahuan AI"**) digating tambahan `internal`.

### Knowledge base — `AiKnowledge` (`ai_knowledge`, `000060`)
1 baris per seksi terpandu (business/products/team/workflow/priorities/okr_strategy/rules/notes). `document()` gabung seksi terisi (≤6000 char) jadi blok "PENGETAHUAN BISNIS" di system prompt — **eksplisit dibingkai sebagai data, bukan instruksi** (hardening prompt-injection).

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

`Lesson::visibleTo()` gate by `is_published` + array `audience` (role names; kosong = semua; super_admin selalu lihat). Preview dokumen: iframe native untuk PDF, embed Microsoft Office Online untuk file Office.

**Izin:** `view_learning` (luas — termasuk reseller) / `manage_learning` (admin). Sidebar: **"SKINKU Academy"**.

---

## 19. Material, Produksi, Supplier

Bagian **Materials & Production** (izin `manage_production`, routes/web.php ~L213-237):
- **`Supplier`** (`suppliers`, `000023`) — master minimal (nama/telp/alamat/catatan/status) → feed `MaterialPurchase`. `nullOnDelete` FK (hapus supplier tak cascade-hapus riwayat beli).
- **`Material` / `MaterialPurchase`** (`000018`/`000019`/`000024`) — bahan baku + pembelian (untuk HPP/costing produksi).
- **`Production` / `ProductionMaterial` / `ProductionCost`** (`000020`-`000022`) — batch produksi barang jadi → menyumbang `hq_stock` + `cogs`.

**Izin:** `manage_production` (material/supplier/produksi), `receive_stock` (penerimaan).

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
| `view_accounting` | admin | Semua laporan/jurnal akuntansi |
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
