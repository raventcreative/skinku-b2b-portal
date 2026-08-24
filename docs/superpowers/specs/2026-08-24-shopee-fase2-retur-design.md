# Integrasi Shopee — Fase 2 (Retur) — Design

**Tanggal:** 2026-08-24
**Status:** Disetujui user (arah: "mirror TikTok sampai kelar, jangan separuh-separuh")
**Referensi:** `docs/superpowers/research/tiktok-fase2-4-map.md` (peta implementasi TikTok yang ditiru)

## Goal

Retur Shopee **parity penuh dengan retur TikTok**: tarik retur dari API otomatis, lalu **review manual** sebelum stok ditambah — cuma barang layak jual yang di-restock; yang cacat ditolak (tidak masuk stok). Pakai "resep SKU" yang sama (`ShopeeOrderService::resolve`) untuk konversi ke produk SKINKU.

**Penegasan lingkup (temuan peta TikTok):** retur **TIDAK menyentuh akuntansi**. `TikTokReturnService` cuma gerak stok lewat `InventoryService::adjustHqStock` — tak ada jurnal untuk restock/reject. Dampak finansial refund lewat jurnal **settlement** (Fase 3/4), bukan di sini. Shopee meniru persis: Fase 2 = **stok saja**.

## Arsitektur

Mirror `TikTokReturnService` **1:1**, di atas backend Shopee Fase 1 yang sudah ada. Marketplace = channel HQ → stok masuk ke **HQ stock** (`adjustHqStock`). Reuse maksimal; tak ada perilaku baru — semua meniru TikTok.

## Global Constraints

- **Zero-dependency**: tanpa composer/npm baru. HTTP pakai bawaan Laravel (sudah begitu di `ShopeeClient`).
- **Mirror TikTok**: struktur file, nama method, alur, UI mengikuti TikTok retur. Jangan mengarang beda.
- **Jangan ubah yang sudah jalan**: `ShopeeOrderService`, `ShopeeClient` (kecuali TAMBAH method retur), model Fase 1 — dipakai apa adanya.
- **Append-only / idempoten**: restock idempoten (guard `REVIEW_RESTOCKED`), aman dijalankan berulang.
- **Retur = stok saja**: TIDAK ada `AccJournal` di fase ini (sama TikTok).
- **Deploy = git pull**: ada 1 migrasi baru (`000093` tabel `shopee_returns`).

## Yang SUDAH ADA (reuse, jangan bikin ulang)

| Aset | Dipakai untuk |
|---|---|
| `ShopeeOrderService::resolve(?string $sku)` | Resep SKU Shopee → komponen produk SKINKU (identik dipakai order & retur) |
| `ShopeeOrderService::skusNeedingMap()` | Sudah membaca `ShopeeOrder.line_items`; akan diperluas agar retur juga ikut (lihat di bawah) |
| `InventoryService::adjustHqStock(...)` | Tambah/kurang stok HQ + catat `StockMovement` |
| `ShopeeConnection` + `ShopeeSyncService::freshToken` | Token 4 jam auto-refresh |
| `ShopeeSyncService::syncOrders` pola paginasi cursor | Pola yang sama untuk `syncReturns` |
| Izin `manage_shopee` + menu Integrasi Shopee | Route retur masuk grup yang sama (tak perlu izin baru) |
| `StockMovement` + Laporan Stok HQ | `reference_type='shopee_return'` otomatis dikenali laporan (kolom retur) |

## Yang DIBANGUN (Fase 2) — semua meniru TikTok

### 1. Model `ShopeeReturn` + migrasi `000093`

Mirror `TiktokReturn`. Tabel `shopee_returns`:

| kolom | tipe | catatan |
|---|---|---|
| `shopee_return_sn` | string, unique | id retur Shopee |
| `shopee_order_sn` | string, nullable, indexed | order asal |
| `status` | string, nullable | status retur mentah Shopee |
| `return_reason` | string, nullable | alasan retur (Shopee kasih `reason`) |
| `line_items` | json, nullable | `[{sku, name, qty}]` |
| `review_status` | string(20), default `pending`, indexed | `pending`/`restocked`/`rejected` |
| `review_note` | text, nullable | |
| `return_created_at` | timestamp, nullable | |
| `reviewed_at` | timestamp, nullable | |
| `reviewed_by` | FK `users`, nullable, `nullOnDelete` | |
| timestamps | | |

Konstanta model: `REVIEW_PENDING='pending'`, `REVIEW_RESTOCKED='restocked'`, `REVIEW_REJECTED='rejected'`. `$casts`: `line_items=>array`, `return_created_at/reviewed_at=>datetime`. Tak ada helper logic di model (semua di service).

### 2. `ShopeeClient` — +2 method (API retur Shopee)

```php
/** Daftar retur dalam rentang waktu (maks 15 hari, sama batas order). */
public function getReturnList(string $accessToken, string $shopId, int $from, int $to, int $pageNo = 0, int $pageSize = 50): array
// GET /api/v2/returns/get_return_list — params: create_time_from/to, page_no, page_size

/** Detail retur (per return_sn) — di sinilah item & alasannya. */
public function getReturnDetail(string $accessToken, string $shopId, string $returnSn): array
// GET /api/v2/returns/get_return_detail — param: return_sn
```

Pakai `shopCall` yang sudah ada (tanda tangan + access_token + shop_id). **Field dipetakan defensif** di service (nama bisa beda) + **diverifikasi ke sandbox** saat build — persis cara Fase 1.

### 3. `ShopeeReturnService` — mirror `TikTokReturnService`

Konstruktor: `ShopeeOrderService $orders`, `InventoryService $inventory`.

| Method | Perilaku (mirror TikTok) |
|---|---|
| `store(array $apiReturns): int` | `updateOrCreate` by `shopee_return_sn`; map field defensif; normalize items; `return_created_at` dari epoch; **tak reset `review_status`** kalau row sudah ada |
| `normalizeItems(array $ret): array` | item retur Shopee → `[{sku, name, qty}]` agregasi per SKU; prioritas `model_sku`→`item_sku`→`item_name` |
| `preview(ShopeeReturn $r): array` | tiap item → komponen produk via `$orders->resolve($sku)`; `{lines, all_matched}` |
| `restock(ShopeeReturn $r, int $userId, ?string $note): void` | idempoten (skip `REVIEW_RESTOCKED`); guard `all_matched`; `adjustHqStock(+qty, TYPE_IN, "Retur Shopee {sn} (layak jual)", 'shopee_return', $r->id)`; set `REVIEW_RESTOCKED` |
| `reject(ShopeeReturn $r, int $userId, ?string $note): void` | kalau sudah restock → `pullBack`; set `REVIEW_REJECTED` |
| `resetReview(ShopeeReturn $r): void` | balik `REVIEW_PENDING`; kalau restock → `pullBack` |
| `pullBack(ShopeeReturn $r): void` (private) | `adjustHqStock(-qty, TYPE_OUT, "Koreksi retur Shopee {sn}", 'shopee_return', $r->id)` |

### 4. `ShopeeSyncService::syncReturns(ShopeeConnection $conn): int`

`freshToken` → `getReturnList` (paginasi `page_no`, guard 40 halaman + `Log::warning` bila cap) → kumpulkan return_sn → `getReturnDetail` per retur → `ShopeeReturnService::store($all)`. Return jumlah tersimpan. Mirror `TikTokSyncService::syncReturns`.

### 5. `ShopeeSyncCommand` — opsi `--returns`

Tambah `{--returns : Sekalian tarik retur}` (mirror `tiktok:sync --returns`). Kalau `--returns`: panggil `syncReturns`, log hasil, try/catch terpisah (satu gagal tak gagalkan order sync). **Cron**: tambah jadwal harian `shopee:sync --returns` (retur jarang berubah, hemat kuota — sama pola TikTok `dailyAt('01:00')`).

### 6. `ShopeeController` — aksi retur (mirror TikTok)

| Method | Perilaku |
|---|---|
| `returnList()` | `ShopeeReturn::latest('return_created_at')->latest('id')->paginate(25)` + `preview()` per row → render `shopee.returns` |
| `syncReturns(Request)` | `$sync->syncReturns($conn)` → redirect + jumlah (hint izin scope Return bila 0) |
| `restockReturn(Request, ShopeeReturn)` | `$service->restock(...)` + `AuditService::log('shopee_return_restock')` |
| `rejectReturn(Request, ShopeeReturn)` | `$service->reject(...)` + audit `shopee_return_reject` |
| `resetReturn(ShopeeReturn)` | `$service->resetReview(...)` |

Inject `ShopeeReturnService` + `ShopeeSyncService` ke konstruktor controller.

### 7. Route + View

Route di grup `permission:manage_shopee` (mirror route retur TikTok):
- `GET /shopee/returns` → `shopee.returns`
- `POST /shopee/returns/sync` → `shopee.returns.sync`
- `POST /shopee/returns/{ret}/restock` → `shopee.returns.restock`
- `POST /shopee/returns/{ret}/reject` → `shopee.returns.reject`
- `POST /shopee/returns/{ret}/reset` → `shopee.returns.reset`

View `resources/views/shopee/returns.blade.php` — mirror `tiktok/returns.blade.php`: tombol "Tarik Retur", kartu per retur (return_sn, order asal, badge status, preview resep SKU tiap item / flag merah bila belum ke-map), tombol aksi bergantung state (pending → "Terima & Tambah Stok" [disabled kalau belum match] + "Tolak (cacat)"; restocked → badge hijau + "batalkan"; rejected → badge merah + "ubah"). **Tanpa** UI jurnal (retur = stok saja).

Menu sidebar: link "Retur" di halaman Integrasi Shopee (mirror TikTok index).

### 8. `skusNeedingMap()` — ikutkan retur

Perluas `ShopeeOrderService::skusNeedingMap()` agar juga membaca `ShopeeReturn.line_items` (mirror TikTok: SKU yang cuma muncul di retur tetap perlu dipetakan). Kalau tak diubah, SKU retur-only tak akan muncul di UI pemetaan.

## Alur data

1. **Sync** — cron `shopee:sync --returns` (harian) / tombol manual → `getReturnList`+`getReturnDetail` → `store` → `shopee_returns` (`review_status=pending`).
2. **Review** — Admin lihat retur → preview dampak stok → **Terima** (layak jual → +stok) / **Tolak** (cacat → no stok). Idempoten. Reject setelah restock → stok ditarik lagi (`pullBack`).
3. **Laporan** — stok masuk `reference_type='shopee_return'` → Laporan Stok HQ otomatis isi kolom retur.

## Error handling

- **SKU retur belum ter-map** → preview `all_matched=false` → restock ditolak sampai di-map (via UI SKU map yang sudah ada).
- **Idempoten** → guard `REVIEW_RESTOCKED` (tak dobel tambah stok).
- **Token 4 jam** → `freshToken` auto-refresh sebelum sync.
- **Page cap 40** → `Log::warning` (mirror order/TikTok).
- **API retur error / scope kurang** → tangkap, tampilkan hint ramah (mungkin butuh izin "Return" di app Shopee), sync retur tak gagalkan order sync.

## Testing (aku sendiri, lokal + sandbox)

Mirror `TikTokTest` bagian retur (3 tes), zero-dependency:
- `test_return_restock_adds_stock_reject_does_not_reverse_pulls_back` — restock +stok + `StockMovement(IN, shopee_return)`; reject tak ubah stok; reset dari restocked → pullBack.
- `test_return_only_sku_appears_in_recipe_panel` — SKU yang cuma di retur muncul di `skusNeedingMap()`.
- `test_return_sync_stores_and_reseller_forbidden` — `POST /shopee/returns/sync` (Http::fake) simpan row; RESELLER → 403 di `GET /shopee/returns`.
- Plus render `shopee.returns` + akses `manage_shopee`.

**Verifikasi sandbox**: panggil `getReturnList` beneran ke sandbox (sign OK, walau kosong), + kalau Shopee Test tool bisa bikin retur, sync end-to-end. Field API dipetakan defensif + dikoreksi ke bentuk asli sandbox.

## Di luar scope Fase 2

- **Fase 3**: Settlement/pencairan Shopee (+ enrichment "kind").
- **Fase 4**: Jurnal akuntansi otomatis Shopee (Kas Shopee `1001` sudah seeded; mint Piutang Shopee; reuse akun shared).
- **AMS / Affiliate** — subsistem terpisah.

## Deploy

- 1 migrasi baru (`000093` `create_shopee_returns_table`) → `git pull` + `migrate --force` + `optimize:clear`.
- Cron `shopee:sync --returns` masuk scheduler otomatis.
