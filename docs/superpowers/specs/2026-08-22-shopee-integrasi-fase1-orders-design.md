# Integrasi Shopee — Fase 1 (Orders + Potong Stok + UI + Cron) — Design

**Tanggal:** 2026-08-22
**Status:** Disetujui user (arah: "mirror TikTok, jangan ada yang dirubah dulu")

## Goal

Nyalakan integrasi Shopee sampai **parity dengan TikTok pada level ORDER**: connect toko → sync order otomatis → potong stok HQ (guardrail preview-approve) → tampil di UI + dashboard. Murni **wiring** di atas backend Shopee yang sudah ada; **tidak** membuat perilaku baru — semua meniru TikTok.

## Arsitektur

Meniru integrasi TikTok 1:1. Reuse maksimal backend Shopee yang sudah ada; tambah lapisan orchestrator + controller + UI + cron + izin. Marketplace = channel jualan HQ → potong **stok HQ** (`adjustHqStock`).

## Global Constraints

- **Zero-dependency**: tanpa composer/npm baru. Client pakai HTTP bawaan Laravel (sudah begitu di ShopeeClient).
- **Mirror TikTok**: struktur file, nama method, alur, dan UI mengikuti TikTok. Jangan mengarang beda.
- **Jangan ubah yang sudah jalan**: dashboard channel sales, ShopeeClient, ShopeeOrderService, model — dipakai apa adanya.
- **Append-only / idempoten**: potong stok idempoten (guard status `deducted`), aman dijalankan berulang.
- **Deploy = git pull**: migrasi hanya kalau perlu (Fase 1 kemungkinan TANPA migrasi — tabel Shopee sudah ada dari migrasi 000042).

## Yang SUDAH ADA (reuse, jangan bikin ulang)

| Aset | Isi | Catatan |
|---|---|---|
| `ShopeeClient` | `authorizeUrl`, `getToken`, `refreshToken`, `getOrderList`, `getOrderDetail`, sign HMAC | Token akses Shopee **~4 jam**, refresh token ~30 hari |
| `ShopeeOrderService` | `store`, `normalizeItems`, `resolve` (SKU map), `preview`, `deduct` (idempoten), `computeHpp`, `cutoff`/`isBeforeCutoff`, `skusNeedingMap` | Potong dari HQ stock; guard cutoff opname |
| Model `ShopeeConnection` | `shop_id` (unik), token+refresh+expiry, `connected_by`, `last_synced_at`, `auto_deduct`, `deduct_from`, `needsRefresh()` | Struktur siap multi-toko; UI pakai `latest()` (1 toko) |
| Model `ShopeeOrder` | Status const (SHIPPED/DELIVERED/PIPELINE/UNCONFIRMED/CANCELLED), `STATUS_PENDING`/`DEDUCTED`, `isShipped`/`isCancelled`, fillable | Lengkap |
| Model `ShopeeSkuMap` | Pemetaan SKU Shopee → produk | Lengkap |
| `ReportService::channelSales` | Bucket **Shopee** (`shopee_orders`, oranye) dgn `Schema::hasTable` guard | **Dashboard penjualan Shopee sudah otomatis** begitu order ke-sync |
| Migrasi 000042 | `shopee_connections`, `shopee_orders`, `shopee_sku_maps` | Sudah ada |
| `config/services.shopee` | `partner_id`, `partner_key`, `api_base` | Isi via `.env` (SHOPEE_PARTNER_ID/KEY) |

## Yang DIBANGUN (Fase 1) — semua meniru TikTok

### 1. `ShopeeSyncService` (orchestrator) — mirror `TikTokSyncService` (bagian order saja)
- `connection(): ?ShopeeConnection` — ambil `latest('id')`.
- `freshToken(ShopeeConnection $conn): string` — kalau `needsRefresh()`, panggil `ShopeeClient::refreshToken` → simpan token+expiry baru → kembalikan access token valid. (Krusial: token 4 jam.)
- `syncOrders(ShopeeConnection $conn, ?int $userId = null, bool $full = false): array` — refresh token → `getOrderList` (paginasi cursor, window waktu; `full` = sapu N order terbaru abaikan filter) → `getOrderDetail` batch → `ShopeeOrderService::store` → set `last_synced_at`. Kalau `conn->auto_deduct`: loop order shipped yang belum dipotong & lolos cutoff → `ShopeeOrderService::deduct`. Return ringkasan (jumlah baru/terupdate/dipotong).
- `toTime(mixed): ?Carbon` — util timestamp Shopee (epoch) → Carbon.

### 2. `ShopeeController` — mirror `TikTokController`
- `index` — status koneksi + tombol connect/sync + toggle auto-deduct + set cutoff + ringkasan + SKU yang perlu map.
- `connect` → redirect `ShopeeClient::authorizeUrl(callbackUrl)`.
- `callback` → tukar `code`+`shop_id` jadi token (`getToken`) → simpan `ShopeeConnection`.
- `syncOrders` (POST) → `ShopeeSyncService::syncOrders` (manual).
- `orderList` — daftar order + status potong + aksi.
- `stockFunnel` — funnel stok/preview dampak (mirror tiktok stock).
- `saveSkuMap` / `removeSkuMap` — kelola pemetaan SKU.
- `deductStock` (1 order) / `deductAll` — potong stok manual (preview-approve).
- `settings` (POST) — update `auto_deduct` + `deduct_from`.

### 3. `ShopeeSyncCommand` — mirror `TikTokSyncCommand`
- Signature: `shopee:sync {--full : sapu N order terbaru}`. (Tanpa `--returns/--settlements` — itu Fase 2/3.)
- Log ke `Log::info('[shopee:sync] ...')`.
- **Schedule** (routes/console.php): `shopee:sync` tiap 30 menit `withoutOverlapping`. (Refresh token tiap jalan karena 4 jam.)

### 4. Views — mirror `resources/views/tiktok/`
- `shopee/index.blade.php`, `shopee/orders.blade.php`, `shopee/stock.blade.php`. Layout/komponen niru TikTok (status koneksi, tombol, tabel order, funnel, form SKU map).

### 5. Izin + Menu + Route
- Permission baru **`manage_shopee`** (label "Integrasi Shopee") di `Permissions::DEFINITIONS` + `DEFAULTS => [ADMIN]` (super_admin implisit).
- Menu **"Integrasi Shopee"** di sidebar (dekat "Integrasi TikTok"), gate `manage_shopee`, ikon Shopee.
- Route group `permission:manage_shopee`: `/shopee`, `/shopee/connect`, `/shopee/callback`, `/shopee/sync-orders`, `/shopee/orders`, `/shopee/stok`, `/shopee/sku-map` (+ remove), `/shopee/orders/{order}/deduct`, `/shopee/deduct-all`, `/shopee/settings`. Mirror route TikTok.

## Alur data

1. **Connect** — Admin (izin `manage_shopee`) → "Hubungkan Shopee" → OAuth Shopee (`authorizeUrl`) → callback (`code`+`shop_id`) → `getToken` → simpan `ShopeeConnection`.
2. **Sync** — cron `shopee:sync` (30 mnt) / tombol manual → `freshToken` → `getOrderList`+`getOrderDetail` → `ShopeeOrderService::store` → `shopee_orders`.
3. **Potong stok** — **default MANUAL (preview-approve)**: admin lihat order → preview dampak → "Potong" (1/semua). `auto_deduct` on → cron ikut potong order shipped otomatis. Potong dari **HQ stock**, idempoten, guard cutoff (`deduct_from`).
4. **Dashboard** — otomatis: `channelSales` sudah baca `shopee_orders` → penjualan Shopee muncul (oranye) begitu ada data.

## Error handling

- **Token akses kadaluarsa (4 jam)** → `freshToken` auto-refresh sebelum tiap sync.
- **Refresh token kadaluarsa (~30 hari, mis. lama tak sync)** → refresh gagal → tandai koneksi & minta **re-connect** di UI (jangan crash cron; log + skip).
- **"wrong sign" / error API Shopee** → tangkap, log, tampilkan pesan ramah di UI; sync tidak menggagalkan seluruh batch.
- **Potong stok**: guard `STATUS_DEDUCTED` (idempoten), guard "belum dikirim" (hanya SHIPPED_STATUSES), guard cutoff, guard SKU belum ter-map (masuk `skusNeedingMap`, tidak dipotong).

## Testing (mirror pola test TikTok, zero-dependency)

- **ShopeeOrderService** (sebagian mungkin sudah ada): `store` idempoten, `deduct` idempoten + guard status/cutoff/mapping, `resolve` SKU map, `computeHpp`.
- **ShopeeSyncService**: `syncOrders` dengan **ShopeeClient di-fake** (response order dummy) → order tersimpan, `last_synced_at` keisi, auto_deduct memotong yang shipped; `freshToken` me-refresh saat `needsRefresh`.
- **ShopeeController**: render `index/orders/stock` + akses `manage_shopee` (non-izin → 403); connect redirect; callback simpan koneksi; deduct manual jalan.
- **Dashboard**: sanity — order Shopee `COMPLETED` muncul di `channelSales` bucket shopee.

## Di luar scope Fase 1 (fase berikutnya)

- **Fase 2**: Retur Shopee (`ShopeeReturn` + service + sync + UI).
- **Fase 3**: Settlement/pencairan Shopee.
- **Fase 4**: Jurnal akuntansi otomatis Shopee (niru `TikTokAccountingService`).
- **AMS / Affiliate** (campaign & komisi affiliate) — subsistem terpisah, brainstorm sendiri.

## Deploy

- Fase 1 kemungkinan **tanpa migrasi** (tabel & kolom Shopee sudah ada dari 000042). Kalau ternyata butuh kolom baru (mis. status koneksi), pakai migrasi 000093+.
- `.env` prod: pastikan `SHOPEE_PARTNER_ID` & `SHOPEE_PARTNER_KEY` terisi + redirect URL callback terdaftar di Shopee Open Platform.
- Setelah deploy: cron `shopee:sync` masuk scheduler; admin connect toko sekali; set `deduct_from` (cutoff opname) sebelum menyalakan auto-deduct.
